<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Delivery;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;

/** The webhook as Telegram sees it, and the conversations as we do. */
final class StatusCommand extends Command
{
    protected $signature = 'threadlive:status';

    protected $description = 'Show the Telegram webhook state and conversation counts';

    public function handle(TelegramClient $telegram): int
    {
        $this->components->twoColumnDetail('Enabled', config('threadlive.enabled') ? 'yes' : '<fg=yellow>no</>');

        try {
            $info = $telegram->call('getWebhookInfo');
            $info = is_array($info) ? $info : [];

            // Whole lines, not columns: a URL or an error cut short helps nobody.
            $url = is_string($info['url'] ?? null) && $info['url'] !== '' ? $info['url'] : null;
            $this->line('  Webhook: '.($url ?? '<fg=red>not set — run php artisan threadlive:setup</>'));
            $this->line('  Updates waiting at Telegram: '.(is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : 0));

            if (is_string($info['last_error_message'] ?? null)) {
                $at = is_int($info['last_error_date'] ?? null) ? Carbon::createFromTimestamp($info['last_error_date'])->toDateTimeString() : '?';
                $this->line("  Last delivery error: <fg=red>{$info['last_error_message']}</> ({$at} UTC)");
            }
            $this->newLine();
        } catch (TelegramException $e) {
            $this->components->twoColumnDetail('Webhook', '<fg=red>'.$e->getMessage().'</>');
        }

        foreach (ConversationStatus::cases() as $status) {
            $this->components->twoColumnDetail(ucfirst($status->value), (string) Conversation::query()->where('status', $status)->count());
        }

        $failed = Message::query()->where('delivery', Delivery::Failed)->count();
        $this->components->twoColumnDetail(
            'Not delivered to Telegram',
            $failed === 0 ? '0' : "<fg=red>{$failed}</> — php artisan threadlive:retry-failed",
        );

        return self::SUCCESS;
    }
}
