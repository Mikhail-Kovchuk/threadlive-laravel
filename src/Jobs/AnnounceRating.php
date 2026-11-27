<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;
use Threadlive\Translation\Languages;
use Threadlive\Translation\TranslationService;

/**
 * The visitor's 👍 / 👎 in the conversation's topic: silent when good, with
 * sound when bad. The comment that follows edits the same post. The topic
 * is left as it is — closed, usually; a deleted one gets nothing.
 */
final class AnnounceRating implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $conversationId)
    {
        DeliverVisitorMessages::useConfiguredQueue($this);
    }

    public static function postKey(int $conversationId): string
    {
        return 'threadlive:rating-post:'.$conversationId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('threadlive-telegram')];
    }

    public function handle(TelegramClient $telegram, TranslationService $translation): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        $thread = $conversation?->telegram_thread_id;

        if ($conversation === null || $thread === null || $conversation->rating === null
            || ! in_array($conversation->topic_state, [TopicState::Open, TopicState::Closed], true)) {
            return;
        }

        $html = self::html($conversation, $translation);
        $bad = $conversation->rating === Conversation::BAD;
        $chatId = (string) config('threadlive.chat_id');

        // One post per close: the comment, or a changed thumb, edits it.
        $closedAt = $conversation->closed_at?->getTimestamp() ?? 0;
        $previous = Cache::get(self::postKey($conversation->id));
        if (is_array($previous) && ($previous['closed'] ?? null) === $closedAt && is_int($previous['message'] ?? null)) {
            try {
                $telegram->call('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $previous['message'],
                    'text' => $html,
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                ]);

                return;
            } catch (TelegramException $e) {
                if ($e->isNotModified()) {
                    return;
                }
                if ($e->retryAfter !== null) {
                    $this->release($e->retryAfter);

                    return;
                }
                // Deleted by hand: posted afresh.
            }
        }

        try {
            $id = $this->send($telegram, $chatId, $thread, $html, $bad);
        } catch (TelegramException $e) {
            if ($e->isMissingTopic()) {
                return;
            }
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }

        Cache::put(self::postKey($conversation->id), ['closed' => $closedAt, 'message' => $id], now()->addDays(30));
    }

    /**
     * Bots that are admins may write in a closed topic; one without the
     * right reopens it for the post and closes it again.
     *
     * @throws TelegramException
     */
    private function send(TelegramClient $telegram, string $chatId, int $thread, string $html, bool $bad): int
    {
        try {
            return $telegram->sendMessage($chatId, $html, $thread, silent: ! $bad);
        } catch (TelegramException $e) {
            if (! str_contains(strtoupper($e->getMessage()), 'TOPIC_CLOSED')) {
                throw $e;
            }
        }

        $telegram->call('reopenForumTopic', ['chat_id' => $chatId, 'message_thread_id' => $thread]);
        try {
            return $telegram->sendMessage($chatId, $html, $thread, silent: ! $bad);
        } finally {
            try {
                $telegram->call('closeForumTopic', ['chat_id' => $chatId, 'message_thread_id' => $thread]);
            } catch (TelegramException $e) {
                report($e);
            }
        }
    }

    /** "👎 Rated: bad" and the comment — translated for the managers when the topic is. */
    public static function html(Conversation $conversation, TranslationService $translation): string
    {
        $html = $conversation->rating === Conversation::BAD
            ? '👎 <b>The visitor rated the conversation: bad</b>'
            : '👍 <b>The visitor rated the conversation: good</b>';

        $comment = trim((string) $conversation->rating_comment);
        if ($comment === '') {
            return $html;
        }

        $html .= "\n💬 ".e(Str::limit($comment, 1000));

        if ($conversation->translate_to !== null) {
            $translated = $translation->translate($comment, $conversation->translate_to);
            if ($translated !== null && ! $translated->isSameLanguage()) {
                $html .= "\n🌐 <b>".Languages::label($translated->source).' → '.Languages::label($translated->target).'</b>: '
                    .e(Str::limit($translated->text, 1000));
            }
        }

        return $html;
    }
}
