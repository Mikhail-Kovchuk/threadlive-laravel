<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Threadlive\Telegram\Dashboard;
use Threadlive\Telegram\TelegramException;

/**
 * The General dashboard, at most once per 30 s however many statuses change:
 * the first change queues it 30 s ahead, the ones in between find it queued.
 */
final class RefreshDashboard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const DEBOUNCE_SECONDS = 30;

    /**
     * Held back by the rate limit, or by another job of this conversation,
     * is not a failure: only real errors count, up to 3, within half an hour.
     */
    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public int $uniqueFor = self::DEBOUNCE_SECONDS;

    public function __construct()
    {
        DeliverVisitorMessages::useConfiguredQueue($this);
        $this->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
    }

    public function uniqueId(): string
    {
        return 'dashboard';
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('threadlive-telegram')];
    }

    public function handle(Dashboard $dashboard): void
    {
        try {
            $dashboard->refresh();
        } catch (TelegramException $e) {
            if ($e->retryAfter === null) {
                throw $e;
            }
            $this->release($e->retryAfter);
        }
    }
}
