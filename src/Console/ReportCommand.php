<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Threadlive\Telegram\Report;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;

/**
 * The report of the last days in General — weekly by the package's
 * schedule (config report) — or printed with --print.
 */
final class ReportCommand extends Command
{
    protected $signature = 'threadlive:report
        {--days=7 : The days it covers, up to now}
        {--print : Print it here instead of posting it in General}';

    protected $description = 'Post the report (conversations, assistant, reply times, ratings) in the support group';

    public function handle(Report $report, TelegramClient $telegram): int
    {
        $days = max(1, (int) $this->option('days'));
        $html = $report->html(now()->subDays($days), now());

        if ($this->option('print')) {
            $this->line(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));

            return self::SUCCESS;
        }

        if (! config('threadlive.enabled') || (string) config('threadlive.bot_token') === '' || (string) config('threadlive.chat_id') === '') {
            $this->components->info('Threadlive is off or not configured; nothing posted. --print shows the report here.');

            return self::SUCCESS;
        }

        try {
            $telegram->sendMessage((string) config('threadlive.chat_id'), $html, null, silent: true);
        } catch (TelegramException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The report is in General.');

        return self::SUCCESS;
    }
}
