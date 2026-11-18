<?php

declare(strict_types=1);

namespace Threadlive;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Threadlive\Contracts\ChatHost;

/**
 * The package's entry point in the host application, found by Laravel's
 * package discovery.
 */
class ThreadliveServiceProvider extends ServiceProvider
{
    /** Requests per minute per visitor: polling every 3 s is 20. */
    private const REQUESTS_PER_MINUTE = 120;

    public function register(): void
    {
        // Merged rather than required, so an application that has not
        // published the config still gets working defaults.
        $this->mergeConfigFrom(__DIR__.'/../config/threadlive.php', 'threadlive');

        $this->app->bind(ChatHost::class, function (Application $app) {
            $class = config('threadlive.host');
            $host = is_string($class) && class_exists($class) ? $app->make($class) : null;

            if (! $host instanceof ChatHost) {
                throw new InvalidArgumentException(
                    'threadlive.host must name a class implementing '.ChatHost::class.'; got '.var_export($class, true).'.'
                );
            }

            return $host;
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/threadlive.php');

        RateLimiter::for('threadlive', function (Request $request) {
            $token = $request->header('X-Threadlive-Token');

            return Limit::perMinute(self::REQUESTS_PER_MINUTE)->by(
                is_string($token) && $token !== '' ? 'threadlive:'.hash('sha256', $token) : 'threadlive-ip:'.$request->ip()
            );
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/threadlive.php' => config_path('threadlive.php'),
            ], 'threadlive-config');

            // Published, never loaded from vendor/: the tables are the
            // application's, and a migration run from here would change
            // under it on the next composer update.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations/2026_11_18_000000_create_threadlive_tables.php' => database_path('migrations/2026_11_18_000000_create_threadlive_tables.php'),
            ], 'threadlive-migrations');
        }
    }
}
