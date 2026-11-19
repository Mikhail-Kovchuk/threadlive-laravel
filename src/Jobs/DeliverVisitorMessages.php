<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
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

    public int $tries = 5;

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
            $conversation->forceFill(['topic_state' => TopicState::Deleted, 'topic_deleted_at' => now()])->save();
            $this->deliver($conversation, $topics, $telegram);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Message::query()
            ->where('conversation_id', $this->conversationId)
            ->where('delivery', Delivery::Pending)
            ->update(['delivery' => Delivery::Failed]);
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

        $this->translate($conversation, $pending);
        $thread = $topics->ensureOpen($conversation);

        foreach ($this->batches($pending) as [$messages, $html, $silent]) {
            $messageId = $telegram->sendMessage($topics->chatId(), $html, $thread, $silent);

            Message::query()->whereKey($messages->pluck('id')->all())->update([
                'delivery' => Delivery::Sent,
                'telegram_message_id' => $messageId,
            ]);
        }

        // The visitor wrote: the topic shows ❗ again, reopened if it was closed.
        $topics->sync($conversation->refresh());
        RefreshDashboard::dispatch();
    }

    /**
     * With /translate on, each visitor message gets its translation for the
     * managers — once: it is kept on the message, so a retry costs nothing.
     * The detected language is the visitor's, which their replies go out in.
     *
     * @param  Collection<int, Message>  $pending
     */
    private function translate(Conversation $conversation, Collection $pending): void
    {
        $target = $conversation->translate_to;
        if ($target === null) {
            return;
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
    }

    /**
     * Consecutive visitor messages become one Telegram message; a context
     * card is its own, silent one. Only visitor messages make a sound.
     * Translations follow the originals: "🌐 ES → EN", then the text.
     *
     * @param  Collection<int, Message>  $pending
     * @return list<array{Collection<int, Message>, string, bool}>
     */
    private function batches(Collection $pending): array
    {
        $batches = [];
        /** @var Collection<int, Message> $run */
        $run = new Collection;
        $length = 0;

        $flush = function () use (&$batches, &$run, &$length) {
            if ($run->isNotEmpty()) {
                $batches[] = [$run, self::compose($run), false];
            }
            $run = new Collection;
            $length = 0;
        };

        foreach ($pending as $message) {
            if ($message->direction === Direction::Context) {
                $flush();
                $batches[] = [new Collection([$message]), '📋 <i>'.e($message->body).'</i>', true];

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

        return $originals."\n\n🌐 <b>".Languages::label($first['from']).' → '.Languages::label($first['to'])."</b>\n"
            .$translated->map(fn (Message $m) => e($m->translation['text'] ?? ''))->implode("\n\n");
    }
}
