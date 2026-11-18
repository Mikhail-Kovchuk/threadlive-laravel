<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;

/**
 * Daily (the service provider schedules it): deletes conversations with no
 * activity for `retention_days` — their messages, their files on disk, and
 * their topic if it still exists. 0 keeps everything.
 */
final class PruneCommand extends Command
{
    protected $signature = 'threadlive:prune';

    protected $description = 'Delete conversations older than threadlive.retention_days, with their files and topics';

    public function handle(TelegramClient $telegram): int
    {
        $days = (int) config('threadlive.retention_days', 90);

        if ($days <= 0) {
            $this->components->info('Retention is off (retention_days = 0); nothing deleted.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        Conversation::query()
            ->where(fn ($q) => $q
                ->where('last_activity_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('last_activity_at')->where('created_at', '<', $cutoff)))
            ->chunkById(100, function ($conversations) use ($telegram, &$deleted) {
                foreach ($conversations as $conversation) {
                    $this->deleteTopic($telegram, $conversation);
                    $this->deleteFiles($conversation);
                    $conversation->messages()->delete();
                    $conversation->delete();
                    $deleted++;
                }
            });

        $this->components->info("Deleted {$deleted} conversations older than {$days} days.");

        return self::SUCCESS;
    }

    private function deleteTopic(TelegramClient $telegram, Conversation $conversation): void
    {
        if ($conversation->telegram_thread_id === null || ! in_array($conversation->topic_state, [TopicState::Open, TopicState::Closed], true)) {
            return;
        }

        try {
            $telegram->call('deleteForumTopic', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_thread_id' => $conversation->telegram_thread_id,
            ]);
        } catch (TelegramException $e) {
            if (! $e->isMissingTopic()) {
                report($e);
            }
        }
    }

    private function deleteFiles(Conversation $conversation): void
    {
        $conversation->messages()->whereNotNull('attachments')->each(function (Message $message) {
            foreach ($message->attachments ?? [] as $file) {
                if (is_string($file['disk'] ?? null) && is_string($file['path'] ?? null)) {
                    Storage::disk($file['disk'])->delete($file['path']);
                }
            }
        });
    }
}
