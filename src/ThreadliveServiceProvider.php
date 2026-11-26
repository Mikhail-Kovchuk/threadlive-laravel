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
use Threadlive\Console\AssistantCommand;
use Threadlive\Console\ConfigCommand;
use Threadlive\Console\FaqCommand;
use Threadlive\Console\InstallCommand;
use Threadlive\Console\ListenCommand;
use Threadlive\Console\MaintainCommand;
use Threadlive\Console\MakeCommandCommand;
use Threadlive\Console\MakePromptCommand;
use Threadlive\Console\PruneCommand;
use Threadlive\Console\RetryFailedCommand;
use Threadlive\Console\SetupCommand;
use Threadlive\Console\StatusCommand;
use Threadlive\Console\TestCommand;
use Threadlive\Contracts\ChatHost;
use Threadlive\Events\VisitorMessageReceived;
use Threadlive\Events\VisitorSignedIn;
use Threadlive\Jobs\AnnounceSignIn;
use Threadlive\Jobs\AnswerWithAssistant;
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

    /** Requests per minute from one IP, whatever tokens they carry. */
    private const REQUESTS_PER_MINUTE_PER_IP = 600;

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

        // Per visitor, and per IP as well: the token is the client's to choose,
        // so a new one per request must not mean a new allowance. The IP limit
        // is wider — an office or a mobile carrier puts many people on one.
        RateLimiter::for('threadlive', function (Request $request) {
            $token = $request->header('X-Threadlive-Token');
            $ip = Limit::perMinute(self::REQUESTS_PER_MINUTE_PER_IP)->by('threadlive-ip:'.$request->ip());

            return is_string($token) && $token !== ''
                ? [Limit::perMinute(self::REQUESTS_PER_MINUTE)->by('threadlive:'.hash('sha256', $token)), $ip]
                : [Limit::perMinute(self::REQUESTS_PER_MINUTE)->by('threadlive-guest:'.$request->ip()), $ip];
        });

        // Telegram allows about 20 messages a minute from a bot into one
        // group; every send of the package goes through this.
        RateLimiter::for('threadlive-telegram', fn () => Limit::perMinute(self::TELEGRAM_PER_MINUTE)->by('threadlive-telegram'));

        // A short delay, so a visitor's burst of messages leaves as one post.
        Event::listen(VisitorMessageReceived::class, function (VisitorMessageReceived $event) {
            // The assistant answers after the topic has the message; the
            // widget shows it "typing" from now.
            if ($event->conversation->withAssistant()) {
                AnswerWithAssistant::typing($event->conversation->id);
            }

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
                FaqCommand::class,
                AssistantCommand::class,
                MakePromptCommand::class,
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
            $migrations = [];
            foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $migration) {
                $migrations[$migration] = database_path('migrations/'.basename($migration));
            }
            $this->publishesMigrations($migrations, 'threadlive-migrations');
        }
    }
}
