<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;
use Threadlive\Telegram\Topics;

/**
 * "👤 Signed in: Anna · anna@example.com · Open in the admin panel" in the
 * topic, and the topic renamed from Guest to the name. The visitor card at
 * the top was posted before anyone knew.
 *
 * Nothing to do while the conversation has no topic: the card of the topic,
 * when it is created, already shows the user.
 */
final class AnnounceSignIn implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $conversationId)
    {
        DeliverVisitorMessages::useConfiguredQueue($this);
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

        if (
            $conversation === null
            || $conversation->telegram_thread_id === null
            || ! in_array($conversation->topic_state, [TopicState::Open, TopicState::Closed], true)
        ) {
            return;
        }

        $line = '👤 Signed in: <b>'.e($conversation->name ?: 'a user').'</b>';
        if ($conversation->email) {
            $line .= ' · '.e($conversation->email);
        }

        $context = $conversation->context ?? [];
        $adminUrl = is_array($context['card'] ?? null) ? ($context['card']['admin_url'] ?? null) : null;
        if (is_string($adminUrl) && (str_starts_with($adminUrl, 'https://') || str_starts_with($adminUrl, 'http://'))) {
            $line .= ' · <a href="'.e($adminUrl).'">Open in the admin panel</a>';
        }

        try {
            $telegram->sendMessage($topics->chatId(), $line, $conversation->telegram_thread_id, silent: true);
            $topics->sync($conversation);
        } catch (TelegramException $e) {
            if ($e->isMissingTopic()) {
                return; // The next message creates a topic whose card shows the user.
            }
            if ($e->retryAfter === null) {
                throw $e;
            }
            $this->release($e->retryAfter);
        }
    }
}
