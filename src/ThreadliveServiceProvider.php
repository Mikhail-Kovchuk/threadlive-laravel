<?php

declare(strict_types=1);

namespace Threadlive;

use Illuminate\Support\ServiceProvider;

/**
 * The package's entry point in the host application, found by Laravel's
 * package discovery.
 */
class ThreadliveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merged rather than required, so an application that has not
        // published the config still gets working defaults.
        $this->mergeConfigFrom(__DIR__.'/../config/threadlive.php', 'threadlive');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/threadlive.php' => config_path('threadlive.php'),
            ], 'threadlive-config');
        }
    }
}
