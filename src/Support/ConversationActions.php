<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Events\ConversationClosed;
use Threadlive\Jobs\RefreshDashboard;
use Threadlive\Jobs\SyncTopic;
use Threadlive\Models\Conversation;

/** What more than one place does to a conversation. */
final class ConversationActions
{
    /**
     * Closes it: the visitor sees "Conversation closed", the topic closes
     * with ✅. Writing again reopens it.
     *
     * @param  'agent'|'auto'  $by
     * @return bool false when it was closed already
     */
    public static function close(Conversation $conversation, string $by): bool
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return false;
        }

        $conversation->messages()->create(['direction' => Direction::System, 'body' => 'Conversation closed']);
        $conversation->forceFill([
            'status' => ConversationStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $by,
        ])->save();

        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();
        event(new ConversationClosed($conversation));

        return true;
    }
}
