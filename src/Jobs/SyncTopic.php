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
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramException;
use Threadlive\Telegram\Topics;

/**
 * Brings a topic's name and open/closed state in line with its conversation
 * after something changed in Telegram (a reply, a claim, /close). Queued,
 * so the webhook answers Telegram at once.
 */
final class SyncTopic implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $conversationId)
    {
        DeliverVisitorMessages::useConfiguredQueue($this);
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

    public function handle(Topics $topics): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        try {
            $topics->sync($conversation);
        } catch (TelegramException $e) {
            if ($e->retryAfter === null) {
                throw $e;
            }
            $this->release($e->retryAfter);
        }
    }
}
