<?php

declare(strict_types=1);

namespace Threadlive;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Threadlive\Console\ConfigCommand;
use Threadlive\Console\InstallCommand;
use Threadlive\Console\ListenCommand;
use Threadlive\Console\MaintainCommand;
use Threadlive\Console\MakeCommandCommand;
use Threadlive\Console\PruneCommand;
use Threadlive\Console\RetryFailedCommand;
use Threadlive\Console\SetupCommand;
use Threadlive\Console\StatusCommand;
use Threadlive\Console\TestCommand;
use Threadlive\Contracts\ChatHost;
use Threadlive\Events\VisitorMessageReceived;
use Threadlive\Events\VisitorSignedIn;
use Threadlive\Jobs\AnnounceSignIn;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Telegram\TelegramClient;

/**
 * The package's entry point in the host application, found by Laravel's
 * package discovery.
 */
class ThreadliveServiceProvider extends ServiceProvider
{
    /** Requests per minute per visitor: polling every 3 s is 20. */
    private const REQUESTS_PER_MINUTE = 120;

    private const TELEGRAM_PER_MINUTE = 20;

    private const DELIVERY_DELAY_SECONDS = 2;

    public function register(): void
    {
        // Merged rather than required, so an application that has not
        // published the config still gets working defaults.
        $this->mergeConfigFrom(__DIR__.'/../config/threadlive.php', 'threadlive');

        $this->app->singleton(TelegramClient::class, fn (Application $app) => new TelegramClient(
            $app->make(HttpFactory::class),
            (string) config('threadlive.bot_token'),
        ));

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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'threadlive');

        RateLimiter::for('threadlive', function (Request $request) {
            $token = $request->header('X-Threadlive-Token');

            return Limit::perMinute(self::REQUESTS_PER_MINUTE)->by(
                is_string($token) && $token !== '' ? 'threadlive:'.hash('sha256', $token) : 'threadlive-ip:'.$request->ip()
            );
        });

        // Telegram allows about 20 messages a minute from a bot into one
        // group; every send of the package goes through this.
        RateLimiter::for('threadlive-telegram', fn () => Limit::perMinute(self::TELEGRAM_PER_MINUTE)->by('threadlive-telegram'));

        // A short delay, so a visitor's burst of messages leaves as one post.
        Event::listen(VisitorMessageReceived::class, function (VisitorMessageReceived $event) {
            DeliverVisitorMessages::dispatch($event->conversation->id)->delay(now()->addSeconds(self::DELIVERY_DELAY_SECONDS));
        });

        Event::listen(VisitorSignedIn::class, function (VisitorSignedIn $event) {
            AnnounceSignIn::dispatch($event->conversation->id);
        });

        // The host's normal `schedule:run` cron is all it needs.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('threadlive:maintain')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('threadlive:prune')->daily()->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ConfigCommand::class,
                ListenCommand::class,
                MakeCommandCommand::class,
                SetupCommand::class,
                StatusCommand::class,
                TestCommand::class,
                MaintainCommand::class,
                PruneCommand::class,
                RetryFailedCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/threadlive.php' => config_path('threadlive.php'),
            ], 'threadlive-config');

            // The offline e-mail, to reword or restyle.
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/threadlive'),
            ], 'threadlive-views');

            // Published, never loaded from vendor/: the tables are the
            // application's, and a migration run from here would change
            // under it on the next composer update.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations/2026_11_18_000000_create_threadlive_tables.php' => database_path('migrations/2026_11_18_000000_create_threadlive_tables.php'),
            ], 'threadlive-migrations');
        }
    }
}
