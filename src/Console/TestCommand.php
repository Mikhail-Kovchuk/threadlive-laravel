<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;

/** Proves the bot can do its job in the group: a topic opened, written in, closed. */
final class TestCommand extends Command
{
    protected $signature = 'threadlive:test';

    protected $description = 'Open a test topic in the support group, post in it and close it';

    public function handle(TelegramClient $telegram): int
    {
        $chatId = (string) config('threadlive.chat_id');

        try {
            $topic = $telegram->call('createForumTopic', ['chat_id' => $chatId, 'name' => '🧪 Threadlive test']);
            $thread = is_array($topic) && is_int($topic['message_thread_id'] ?? null) ? $topic['message_thread_id'] : null;

            if ($thread === null) {
                $this->components->error('Telegram created no topic.');

                return self::FAILURE;
            }

            $telegram->sendMessage($chatId, 'If you can read this, Threadlive can post here. This topic can be deleted.', $thread);
            $telegram->call('closeForumTopic', ['chat_id' => $chatId, 'message_thread_id' => $thread]);
        } catch (TelegramException $e) {
            $this->components->error($e->getMessage().' — run php artisan threadlive:setup to see what is missing.');

            return self::FAILURE;
        }

        $this->components->info('Posted in a new topic “🧪 Threadlive test” and closed it.');

        return self::SUCCESS;
    }
}
