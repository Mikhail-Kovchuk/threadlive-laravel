<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Threadlive\Enums\Delivery;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Models\Message;

/**
 * Visitor messages the delivery job gave up on (Telegram down, the bot
 * removed from the group, …) go back in the queue. The dashboard's
 * "⚠️ Not delivered" line counts them until then.
 */
final class RetryFailedCommand extends Command
{
    protected $signature = 'threadlive:retry-failed
        {--conversation= : Only this conversation (its number, as in "#12")}';

    protected $description = 'Send visitor messages that could not be delivered to Telegram again';

    public function handle(): int
    {
        $query = Message::query()->where('delivery', Delivery::Failed);

        $only = $this->option('conversation');
        if (is_string($only) && $only !== '') {
            $query->where('conversation_id', (int) ltrim($only, '#'));
        }

        $conversationIds = (clone $query)->distinct()->pluck('conversation_id')->map(fn ($id) => (int) $id)->all();

        if ($conversationIds === []) {
            $this->components->info('Nothing failed; nothing to send.');

            return self::SUCCESS;
        }

        $count = $query->update(['delivery' => Delivery::Pending]);

        foreach ($conversationIds as $id) {
            DeliverVisitorMessages::dispatch($id);
        }

        $this->components->info(sprintf(
            'Queued %d %s in %d %s.',
            $count,
            $count === 1 ? 'message' : 'messages',
            count($conversationIds),
            count($conversationIds) === 1 ? 'conversation' : 'conversations',
        ));

        return self::SUCCESS;
    }
}
