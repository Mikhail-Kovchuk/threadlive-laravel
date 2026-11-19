<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Threadlive\Telegram\Dashboard;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;

/**
 * Checks the bot and the group, says exactly what is wrong, and — when
 * nothing is — sets the webhook and posts the dashboard. Safe to run again
 * at any time.
 *
 * --local skips the webhook (no public HTTPS address on a development
 * machine): updates then come in through threadlive:listen.
 */
final class SetupCommand extends Command
{
    protected $signature = 'threadlive:setup
        {--local : Check the bot and the group but set no webhook; receive updates with threadlive:listen}';

    protected $description = 'Check the Telegram bot and group, set the webhook, post the dashboard';

    private const REQUIRED_RIGHTS = [
        'can_manage_topics' => 'Manage topics',
        'can_delete_messages' => 'Delete messages',
        'can_pin_messages' => 'Pin messages',
    ];

    private bool $ok = true;

    public function handle(TelegramClient $telegram, Dashboard $dashboard): int
    {
        $bot = $this->bot($telegram);
        if ($bot === null) {
            return self::FAILURE;
        }

        $chatId = (string) config('threadlive.chat_id');
        if ($chatId === '') {
            $this->problem('Group', 'THREADLIVE_GROUP_ID is empty. Add the bot to your group; the group id starts with -100.');

            return self::FAILURE;
        }

        if (! $this->group($telegram, $chatId)) {
            return self::FAILURE;
        }

        $this->rights($telegram, $chatId, $bot);
        $local = (bool) $this->option('local');
        $url = $local ? null : $this->webhookUrl();

        if (! $this->ok || (! $local && $url === null)) {
            $this->newLine();
            $this->components->error('Fix the above and run php artisan threadlive:setup again.');

            return self::FAILURE;
        }

        if ($url !== null) {
            $telegram->call('setWebhook', [
                'url' => $url,
                'secret_token' => (string) config('threadlive.webhook_secret'),
                'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            ]);
            $this->pass('Webhook', $url);
        } else {
            $this->pass('Webhook', 'none (local): updates come through threadlive:listen');
        }

        $dashboard->refresh();
        $this->pass('Dashboard', 'posted in General');

        $this->newLine();
        if (! config('threadlive.enabled')) {
            $this->components->warn('Set THREADLIVE_ENABLED=true to show the widget.');
        }
        $this->components->info($local
            ? 'Telegram is set up for local work. Keep php artisan threadlive:listen and a queue worker running.'
            : 'Telegram is set up. Try it: php artisan threadlive:test');

        return self::SUCCESS;
    }

    /** @return array{id: int, username: string}|null */
    private function bot(TelegramClient $telegram): ?array
    {
        if ((string) config('threadlive.bot_token') === '') {
            $this->problem('Bot', 'THREADLIVE_BOT_TOKEN is empty. Create a bot with @BotFather — a new one, not a bot this app already uses.');

            return null;
        }

        try {
            $me = $telegram->call('getMe');
        } catch (TelegramException $e) {
            $this->problem('Bot', 'Telegram does not accept THREADLIVE_BOT_TOKEN ('.$e->getMessage().').');

            return null;
        }

        $id = is_array($me) && is_int($me['id'] ?? null) ? $me['id'] : 0;
        $username = is_array($me) && is_string($me['username'] ?? null) ? $me['username'] : '?';
        $this->pass('Bot', '@'.$username);

        return ['id' => $id, 'username' => $username];
    }

    private function group(TelegramClient $telegram, string $chatId): bool
    {
        try {
            $chat = $telegram->call('getChat', ['chat_id' => $chatId]);
        } catch (TelegramException $e) {
            if ($e->migrateToChatId !== null) {
                // Turning Topics on makes a group a supergroup, with a new id.
                $this->problem('Group', "The group became a supergroup with a new id. Set THREADLIVE_GROUP_ID={$e->migrateToChatId}.");
            } else {
                $this->problem('Group', "The bot cannot see group {$chatId}: is the id right, and is the bot in the group? ({$e->getMessage()})");
            }

            return false;
        }

        $title = is_array($chat) && is_string($chat['title'] ?? null) ? $chat['title'] : $chatId;

        if (! is_array($chat) || ($chat['is_forum'] ?? false) !== true) {
            $this->problem('Group', "Topics are off in “{$title}”. Turn them on in the group settings, then run this again (the group may get a new id).");

            return false;
        }

        $this->pass('Group', "“{$title}”, topics on");

        return true;
    }

    /**
     * @param  array{id: int, username: string}  $bot
     */
    private function rights(TelegramClient $telegram, string $chatId, array $bot): void
    {
        try {
            $member = $telegram->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $bot['id']]);
        } catch (TelegramException $e) {
            $this->problem('Bot rights', $e->getMessage());

            return;
        }

        $member = is_array($member) ? $member : [];

        if (($member['status'] ?? null) !== 'administrator') {
            $this->problem('Bot rights', '@'.$bot['username'].' is not an admin of the group. Make it one, with '.implode(', ', self::REQUIRED_RIGHTS).'.');

            return;
        }

        $missing = array_values(array_filter(
            self::REQUIRED_RIGHTS,
            fn (string $right) => ($member[$right] ?? false) !== true,
            ARRAY_FILTER_USE_KEY,
        ));

        if ($missing !== []) {
            $this->problem('Bot rights', 'Give @'.$bot['username'].' these admin rights: '.implode(', ', $missing).'.');

            return;
        }

        $this->pass('Bot rights', implode(', ', self::REQUIRED_RIGHTS));
    }

    private function webhookUrl(): ?string
    {
        if ((string) config('threadlive.webhook_secret') === '') {
            $this->problem('Webhook', 'THREADLIVE_WEBHOOK_SECRET is empty. Run php artisan threadlive:install, or put any 32+ random letters and digits there.');

            return null;
        }

        $url = route('threadlive.webhook');

        if (! str_starts_with($url, 'https://')) {
            $this->problem('Webhook', "Telegram calls HTTPS addresses only, and APP_URL makes this {$url}. "
                .'Set APP_URL to the public https address — on a development machine, a tunnel (cloudflared, ngrok).');

            return null;
        }

        return $url;
    }

    private function pass(string $what, string $detail): void
    {
        $this->components->twoColumnDetail($what, '<fg=green>✓ '.$detail.'</>');
    }

    private function problem(string $what, string $problem): void
    {
        $this->ok = false;
        $this->components->twoColumnDetail($what, '<fg=red>✗</>');
        $this->components->error($problem);
    }
}
