<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Sleep;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;
use Threadlive\Telegram\UpdateHandler;
use Throwable;

/**
 * Local development without a public HTTPS address: asks Telegram for
 * updates (getUpdates, long polling) and hands each to the same handler the
 * webhook uses. Production keeps the webhook.
 *
 * Telegram gives a bot one way or the other: with a webhook set, getUpdates
 * is refused. This command never removes a webhook on its own — run with
 * the token of a production bot, it would take the managers' replies away
 * from the live site — only with --drop-webhook.
 */
final class ListenCommand extends Command
{
    protected $signature = 'threadlive:listen
        {--drop-webhook : Remove the bot\'s webhook first (never on a production bot)}
        {--once : One round of updates, then stop}';

    protected $description = 'Receive Telegram updates by polling, for local development without HTTPS';

    private const OFFSET_KEY = 'threadlive:listen:offset';

    /** Seconds Telegram holds a getUpdates open when there is nothing new. */
    private const LONG_POLL = 25;

    private const ERROR_PAUSE = 5;

    /** With --once: how often to try reaching Telegram before giving up. */
    private const ONCE_TRIES = 3;

    public function handle(TelegramClient $telegram, UpdateHandler $handler, Cache $cache): int
    {
        if (! $this->webhookOutOfTheWay($telegram)) {
            return self::FAILURE;
        }

        $this->components->info('Listening for Telegram updates. Ctrl+C to stop.');

        do {
            $offset = $cache->get(self::OFFSET_KEY);

            try {
                $updates = $telegram->call('getUpdates', [
                    'offset' => is_int($offset) ? $offset : null,
                    'timeout' => $this->option('once') ? 0 : self::LONG_POLL,
                    'allowed_updates' => ['message', 'edited_message', 'callback_query'],
                ], timeout: self::LONG_POLL + 10);
            } catch (TelegramException $e) {
                $this->components->error($e->getMessage());
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                Sleep::for(self::ERROR_PAUSE)->seconds();

                continue;
            }

            foreach (is_array($updates) ? $updates : [] as $update) {
                if (! is_array($update) || ! is_int($update['update_id'] ?? null)) {
                    continue;
                }

                try {
                    $handler->handle($update);
                    $this->line('  '.now()->format('H:i:s').'  '.$this->describe($update));
                } catch (Throwable $e) {
                    // Like the webhook: report and move on, or one bad update
                    // would come back forever and hold up the rest.
                    report($e);
                    $this->components->error('Update '.$update['update_id'].': '.$e->getMessage());
                }

                // Confirmed by asking from the next id on.
                $cache->forever(self::OFFSET_KEY, $update['update_id'] + 1);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function webhookOutOfTheWay(TelegramClient $telegram): bool
    {
        // Telegram out of reach for a moment is no reason to stop — under
        // `composer dev` (concurrently --kill-others) it would take the
        // server and the queue down with it. Telegram saying no (a wrong
        // token) is. --once gives up after a few tries.
        for ($attempt = 1; ; $attempt++) {
            try {
                $info = $telegram->call('getWebhookInfo');

                break;
            } catch (TelegramException $e) {
                $this->components->error($e->getMessage());

                if (! $e->connectionFailed || ($this->option('once') && $attempt >= self::ONCE_TRIES)) {
                    return false;
                }

                $this->components->warn('Telegram is out of reach; trying again in '.self::ERROR_PAUSE.' s.');
                Sleep::for(self::ERROR_PAUSE)->seconds();
            }
        }

        $url = is_array($info) && is_string($info['url'] ?? null) ? $info['url'] : '';

        if ($url === '') {
            return true;
        }

        if (! $this->option('drop-webhook')) {
            $this->components->error("This bot has a webhook ({$url}), and Telegram gives updates to one or the other. "
                .'If this is a development bot, run again with --drop-webhook. Never on the production bot: '
                .'its managers\' replies would stop reaching the live site.');

            return false;
        }

        $telegram->call('deleteWebhook');
        $this->components->warn("Removed the webhook ({$url}). threadlive:setup sets it again.");

        return true;
    }

    /**
     * @param  array<mixed>  $update
     */
    private function describe(array $update): string
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (is_array($message)) {
            $who = is_string($message['from']['first_name'] ?? null) ? $message['from']['first_name'] : '?';
            $where = is_int($message['message_thread_id'] ?? null) ? 'topic '.$message['message_thread_id'] : 'General';
            $what = isset($update['edited_message']) ? 'edit' : 'message';

            return "{$what} from {$who} in {$where}";
        }

        if (is_array($update['callback_query'] ?? null)) {
            return 'button: '.(is_string($update['callback_query']['data'] ?? null) ? $update['callback_query']['data'] : '?');
        }

        return 'update '.$update['update_id'];
    }
}
