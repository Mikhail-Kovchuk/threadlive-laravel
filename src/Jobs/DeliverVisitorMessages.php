<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Threadlive\Enums\Delivery;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;
use Threadlive\Telegram\Topics;
use Threadlive\Translation\Languages;
use Threadlive\Translation\TranslationService;
use Throwable;

/**
 * Posts a conversation's pending visitor messages in its topic.
 *
 * One job per conversation at a time; dispatched with a short delay, so a
 * burst of messages leaves as one Telegram message. Behind the group's rate
 * limit (~20 messages a minute into one group). The visitor never waits for
 * any of it: their message is stored, and "Delivered" means that.
 */
final class DeliverVisitorMessages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Telegram allows 4096; leave room for the joins. */
    private const MAX_TEXT = 3800;

    private const TELEGRAM_MAX = 4096;

    /** Longer quotes start collapsed. */
    private const LONG_QUOTE = 300;

    /** translation.auto tells a language only from a message this long. */
    private const DETECT_MIN_CHARS = 4;

    /**
     * Held back by the rate limit, or by another job of this conversation,
     * is not a failure: only real errors count, up to 5, within two hours.
     */
    public int $maxExceptions = 5;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * The "one job per conversation" lock only gathers a burst into one job.
     * It must not outlive that: a job lost before it ran (a worker started
     * before this package was installed, a flushed queue) would otherwise
     * keep every later message of the conversation from being sent. A second
     * job queued after it expires does no harm — it finds nothing pending.
     */
    public int $uniqueFor = self::UNIQUE_SECONDS;

    public const UNIQUE_SECONDS = 60;

    public function __construct(public readonly int $conversationId)
    {
        self::useConfiguredQueue($this);
    }

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('threadlive:'.$this->conversationId))->releaseAfter(5)->expireAfter(180),
            new RateLimited('threadlive-telegram'),
        ];
    }

    public function handle(Topics $topics, TelegramClient $telegram): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        try {
            $this->deliver($conversation, $topics, $telegram);
        } catch (TelegramException $e) {
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            if (! $e->isMissingTopic()) {
                throw $e;
            }

            // Deleted by hand in Telegram: a new topic, with a summary, and
            // the messages again. Never lose one.
            // What went into the old topic so far goes again into the new one.
            $conversation->forceFill(['topic_state' => TopicState::Deleted, 'topic_deleted_at' => now()])->save();
            $conversation->messages()->where('delivery', Delivery::Pending)->update(['telegram_message_id' => null]);
            $this->deliver($conversation, $topics, $telegram);
        }
    }

    /**
     * What this job tried and could not send is Failed — not messages that
     * arrived since: their own job sends them.
     */
    public function failed(?Throwable $exception): void
    {
        $upTo = Cache::pull(self::upToKey($this->conversationId));

        Message::query()
            ->where('conversation_id', $this->conversationId)
            ->where('delivery', Delivery::Pending)
            ->when(is_int($upTo), fn ($q) => $q->where('id', '<=', $upTo))
            ->update(['delivery' => Delivery::Failed]);

        // The dashboard's "⚠️ Not delivered" line is how managers learn of it.
        RefreshDashboard::dispatch();

        // The visitor still gets their answer: the topic is only the
        // managers' copy.
        $conversation = Conversation::query()->find($this->conversationId);
        if ($conversation?->withAssistant() === true) {
            AnswerWithAssistant::dispatch($conversation->id);
        }
    }

    private static function upToKey(int $conversationId): string
    {
        return 'threadlive:delivering:'.$conversationId;
    }

    /**
     * Telegram takes up to 4096 characters. A batch is kept under that; one
     * very long message (with its translation) is not — it goes as several
     * plain parts instead of being refused forever.
     *
     * @param  Collection<int, Message>  $messages
     * @return list<string>
     */
    private static function fit(string $html, Collection $messages): array
    {
        if (mb_strlen($html) <= self::TELEGRAM_MAX) {
            return [$html];
        }

        $parts = [];
        foreach ($messages as $message) {
            array_push($parts, ...self::chunks($message->body));

            $translation = $message->translation;
            if ($translation !== null) {
                $label = '🌐 <b>'.Languages::label($translation['from']).' → '.Languages::label($translation['to']).'</b>';
                $chunks = self::chunks($translation['text']);
                $chunks[0] = $label."\n".$chunks[0];
                array_push($parts, ...$chunks);
            }
        }

        return $parts;
    }

    /**
     * Plain text cut into escaped pieces that each fit in a message.
     *
     * @return list<string>
     */
    private static function chunks(string $text): array
    {
        $chunks = [];
        $current = '';

        foreach (mb_str_split($text, 200) as $piece) {
            if ($current !== '' && mb_strlen(e($current.$piece)) > self::MAX_TEXT) {
                $chunks[] = e($current);
                $current = '';
            }
            $current .= $piece;
        }

        // The loop always leaves the last piece here ('' for no text at all).
        $chunks[] = e($current);

        return $chunks;
    }

    /** The queue connection and name from config('threadlive.queue'). */
    public static function useConfiguredQueue(ShouldQueue $job): void
    {
        $queue = config('threadlive.queue');
        $queue = is_array($queue) ? $queue : [];

        if (is_string($queue['connection'] ?? null) && method_exists($job, 'onConnection')) {
            $job->onConnection($queue['connection']);
        }
        if (is_string($queue['name'] ?? null) && method_exists($job, 'onQueue')) {
            $job->onQueue($queue['name']);
        }
    }

    private function deliver(Conversation $conversation, Topics $topics, TelegramClient $telegram): void
    {
        $pending = $conversation->messages()
            ->whereIn('direction', [Direction::Visitor, Direction::Context])
            ->where('delivery', Delivery::Pending)
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        Cache::put(self::upToKey($conversation->id), (int) $pending->max('id'), now()->addDay());

        // While the assistant answers, the managers are not called: no sound.
        $quiet = $conversation->withAssistant();
        $visitorWrote = $pending->contains(fn (Message $m) => $m->direction === Direction::Visitor);

        // A text that went out on an earlier try, whose files did not:
        // the text is not posted twice.
        $wentOut = fn (Message $m) => $m->telegram_message_id !== null && ($m->attachments === null || $m->attachments === []);
        $textSent = $pending->filter($wentOut);
        $pending = $pending->reject($wentOut);
        if ($textSent->isNotEmpty()) {
            Message::query()->whereKey($textSent->pluck('id')->all())->update(['delivery' => Delivery::Sent]);
        }

        $autoTranslation = $this->translate($conversation, $pending);
        $thread = $topics->ensureOpen($conversation);

        foreach ($this->batches($pending->values()) as [$messages, $html, $silent, $files]) {
            $ids = $messages->pluck('id')->all();
            $messageId = $messages->first()?->telegram_message_id;

            if ($html !== null && $messageId === null) {
                foreach (self::fit($html, $messages) as $i => $part) {
                    $partId = $telegram->sendMessage($topics->chatId(), $part, $thread, $silent || $quiet || $i > 0);
                    $messageId ??= $partId;
                }
                Message::query()->whereKey($ids)->update(['telegram_message_id' => $messageId]);
            }

            // The files after the text; the first one makes the sound when
            // there was no text to make it. Each is noted as sent, so a retry
            // after a failure halfway sends only the rest.
            $owner = $messages->first();
            $sentOne = $html !== null;
            foreach ($files as $key => $file) {
                if (isset($file['telegram_message_id'])) {
                    $sentOne = true;

                    continue;
                }

                $fileMessageId = $this->sendFile($telegram, $topics->chatId(), $thread, $file, silent: $sentOne || $quiet);
                $sentOne = true;
                $messageId ??= $fileMessageId;

                if ($owner !== null && is_array($owner->attachments)) {
                    $attachments = $owner->attachments;
                    $attachments[$key]['telegram_message_id'] = $fileMessageId ?? 0;
                    $owner->forceFill(['attachments' => $attachments])->save();
                }
            }

            Message::query()->whereKey($ids)->update([
                'delivery' => Delivery::Sent,
                'telegram_message_id' => $messageId,
            ]);
        }

        Cache::forget(self::upToKey($conversation->id));

        if ($autoTranslation && $conversation->visitor_language !== null && $conversation->translate_to !== null) {
            $from = Languages::label($conversation->visitor_language);
            $telegram->sendMessage(
                $topics->chatId(),
                '🌐 Translation on automatically: <b>'.$from.' → '.Languages::label($conversation->translate_to).'</b>. '
                    .'Your replies go out in <b>'.$from.'</b>. Off: ⚙️ Actions → 🚫 Off, or /translate off.',
                $thread,
            );
        }

        // The visitor wrote: the topic shows ❗ again, reopened if it was closed.
        $topics->sync($conversation->refresh());
        RefreshDashboard::dispatch();

        // Now that the topic has the question, the assistant's answer.
        if ($visitorWrote && $conversation->withAssistant()) {
            AnswerWithAssistant::dispatch($conversation->id);
        }
    }

    /**
     * With /translate on, each visitor message gets its translation for the
     * managers — once: it is kept on the message, so a retry costs nothing.
     * The detected language is the visitor's, which their replies go out in.
     *
     * @param  Collection<int, Message>  $pending
     * @return bool translation was just turned on by translation.auto
     */
    private function translate(Conversation $conversation, Collection $pending): bool
    {
        $auto = $this->detectLanguage($conversation, $pending);

        $target = $conversation->translate_to;
        if ($target === null) {
            return $auto;
        }

        $translator = app(TranslationService::class);

        foreach ($pending as $message) {
            if ($message->direction !== Direction::Visitor || $message->translation !== null) {
                continue;
            }

            $translation = $translator->translate($message->body, $target);
            if ($translation === null) {
                continue;
            }

            if ($translation->source !== '' && $conversation->visitor_language !== $translation->source) {
                $conversation->forceFill(['visitor_language' => $translation->source])->save();
            }

            if (! $translation->isSameLanguage()) {
                $message->forceFill(['translation' => $translation->toArray()])->save();
            }
        }

        return $auto;
    }

    /**
     * translation.auto: the first visitor message long enough to tell
     * tells the visitor's language; one other than the managers' turns
     * translation on for the conversation. Once: a known visitor language
     * (found here, or by /translate) stops it, so a manager's Off stays off.
     * A translator that cannot answer now leaves it to the next message.
     *
     * @param  Collection<int, Message>  $pending
     */
    private function detectLanguage(Conversation $conversation, Collection $pending): bool
    {
        if (config('threadlive.translation.auto', false) !== true
            || $conversation->translate_to !== null
            || $conversation->visitor_language !== null) {
            return false;
        }

        $translator = app(TranslationService::class);
        if (! $translator->enabled()) {
            return false;
        }

        $target = $translator->defaultTarget();

        foreach ($pending as $message) {
            // "ok", "hi": too short to tell a language by.
            if ($message->direction !== Direction::Visitor || mb_strlen(trim($message->body)) < self::DETECT_MIN_CHARS) {
                continue;
            }

            $translation = $translator->translate($message->body, $target);
            if ($translation === null || $translation->source === '') {
                return false;
            }

            if ($translation->isSameLanguage()) {
                $conversation->forceFill(['visitor_language' => $translation->source])->save();

                return false;
            }

            $conversation->forceFill(['translate_to' => $target, 'visitor_language' => $translation->source])->save();
            $message->forceFill(['translation' => $translation->toArray()])->save();

            return true;
        }

        return false;
    }

    /**
     * A photo (sendPhoto) or anything else (sendDocument; GIFs too, which
     * sendPhoto would flatten). A photo Telegram refuses — too large, odd
     * proportions — goes as a document instead.
     *
     * @param  array<string, mixed>  $file
     *
     * @throws TelegramException
     */
    private function sendFile(TelegramClient $telegram, string $chatId, int $thread, array $file, bool $silent): ?int
    {
        $disk = is_string($file['disk'] ?? null) ? $file['disk'] : null;
        $path = is_string($file['path'] ?? null) ? $file['path'] : null;
        $contents = $disk !== null && $path !== null ? Storage::disk($disk)->get($path) : null;

        if ($contents === null) {
            return null; // Gone from the disk (pruned): nothing to send.
        }

        $mime = is_string($file['mime'] ?? null) ? $file['mime'] : '';
        $name = is_string($file['name'] ?? null) ? $file['name'] : 'file';
        $params = ['chat_id' => $chatId, 'message_thread_id' => $thread, 'disable_notification' => $silent];

        if (str_starts_with($mime, 'image/') && $mime !== 'image/gif') {
            try {
                return self::messageId($telegram->upload('sendPhoto', $params, ['photo', $contents, $name]));
            } catch (TelegramException $e) {
                if ($e->isMissingTopic() || $e->retryAfter !== null) {
                    throw $e;
                }
            }
        }

        return self::messageId($telegram->upload('sendDocument', $params, ['document', $contents, $name]));
    }

    private static function messageId(mixed $result): ?int
    {
        return is_array($result) && is_int($result['message_id'] ?? null) ? $result['message_id'] : null;
    }

    /**
     * Consecutive visitor messages become one Telegram message; a context
     * card is its own, silent one; a message with files is its own — its
     * text, then the files. Only visitor messages make a sound.
     * Translations follow the originals: "🌐 ES → EN", then the text.
     *
     * @param  Collection<int, Message>  $pending
     * @return list<array{Collection<int, Message>, string|null, bool, array<array-key, array<string, mixed>>}>
     */
    private function batches(Collection $pending): array
    {
        $batches = [];
        /** @var Collection<int, Message> $run */
        $run = new Collection;
        $length = 0;

        $flush = function () use (&$batches, &$run, &$length) {
            if ($run->isNotEmpty()) {
                $batches[] = [$run, self::compose($run), false, []];
            }
            $run = new Collection;
            $length = 0;
        };

        foreach ($pending as $message) {
            if ($message->direction === Direction::Context) {
                $flush();
                $batches[] = [new Collection([$message]), '📋 <i>'.e($message->body).'</i>', true, []];

                continue;
            }

            if ($message->attachments !== null && $message->attachments !== []) {
                $flush();
                $own = new Collection([$message]);
                $batches[] = [$own, trim($message->body) === '' ? null : self::compose($own), false, $message->attachments];

                continue;
            }

            $size = mb_strlen($message->body) + mb_strlen($message->translation['text'] ?? '') + 4;
            if ($run->isNotEmpty() && $length + $size > self::MAX_TEXT) {
                $flush();
            }

            $run->push($message);
            $length += $size;
        }

        $flush();

        return $batches;
    }

    /**
     * Untranslated: the text as written. Translated: the visitor's original
     * in a quote — the one rule in the topic is "quoted = the visitor's
     * language" — then "🌐 ES → EN" and the translation as plain text. A long
     * original is a collapsed quote, so the translation stays in view.
     *
     * @param  Collection<int, Message>  $messages
     */
    private static function compose(Collection $messages): string
    {
        $originals = $messages->map(fn (Message $m) => e($m->body))->implode("\n\n");
        $translated = $messages->filter(fn (Message $m) => $m->translation !== null);

        if ($translated->isEmpty()) {
            return $originals;
        }

        /** @var array{text: string, from: string, to: string} $first */
        $first = $translated->first()->translation;

        return self::quote($originals)
            .'🌐 <b>'.Languages::label($first['from']).' → '.Languages::label($first['to'])."</b>\n"
            .$translated->map(fn (Message $m) => e($m->translation['text'] ?? ''))->implode("\n\n");
    }

    /**
     * Text in the visitor's language, as a quote; collapsed when long. Ends
     * with a line break: Telegram Desktop draws a short line right after a
     * short quote inside the quote ("Hi🌐 EN → RU", seen 2026-11-25).
     */
    public static function quote(string $escapedHtml): string
    {
        $tag = mb_strlen($escapedHtml) > self::LONG_QUOTE ? '<blockquote expandable>' : '<blockquote>';

        return $tag.$escapedHtml."</blockquote>\n";
    }
}
