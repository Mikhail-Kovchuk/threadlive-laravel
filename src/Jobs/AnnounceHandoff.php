<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Threadlive\Assistant\AssistantService;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Telegram\AssistantPosts;
use Threadlive\Telegram\TelegramException;

/**
 * A handoff, in the topic: what the assistant told the visitor (if it did),
 * then "🙋 Needs a manager" with sound. From the queue: the visitor's
 * "Talk to a person" never waits for Telegram.
 */
final class AnnounceHandoff implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $maxExceptions = 3;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $summary = '',
        public readonly ?int $noticeId = null,
        public readonly string $noticeForManagers = '',
    ) {
        DeliverVisitorMessages::useConfiguredQueue($this);
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

    public function handle(AssistantPosts $posts, AssistantService $assistant): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        try {
            $notice = $this->noticeId === null ? null : Message::query()->find($this->noticeId);
            if ($notice !== null && $notice->telegram_message_id === null) {
                $posts->reply($conversation, $notice, $this->noticeForManagers, $assistant->managersLanguage());
            }

            $posts->handoff($conversation, $this->summary);
        } catch (TelegramException $e) {
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }
    }
}
