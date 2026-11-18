<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Threadlive\Enums\ConversationStatus;
use Threadlive\Models\Conversation;

/**
 * "❗ Anna · #12", "💬 Maria → Anna · #12", "✅ Anna · #12": a list of fifty
 * topics reads without opening them.
 *
 * No waiting time in the name: it would go stale at once, and renaming on
 * every message runs into Telegram's rate limits.
 */
final class TopicName
{
    private const MAX = 128;

    public static function for(Conversation $conversation): string
    {
        $status = match ($conversation->status) {
            ConversationStatus::Waiting => '❗',
            ConversationStatus::Answered => '💬',
            ConversationStatus::Closed => '✅',
        };

        $visitor = $conversation->name !== null && trim($conversation->name) !== '' ? trim($conversation->name) : 'Guest';
        $who = $conversation->assignee_name ? "{$conversation->assignee_name} → {$visitor}" : $visitor;

        return mb_substr("{$status} {$who} · #{$conversation->id}", 0, self::MAX);
    }
}
