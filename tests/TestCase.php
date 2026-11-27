<?php

declare(strict_types=1);

namespace Threadlive\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase as Orchestra;
use Threadlive\Support\GroupSettings;
use Threadlive\Tests\Fixtures\FakeChatHost;
use Threadlive\ThreadliveServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeChatHost::reset();
        // Jobs are run by hand where a test is about them.
        Queue::fake();
        // What admins set from Telegram lives in a file: none left from the last test.
        @unlink(GroupSettings::path());
        GroupSettings::flush();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ThreadliveServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('threadlive.enabled', true);
        $app['config']->set('threadlive.host', FakeChatHost::class);
        $app['config']->set('threadlive.bot_token', '123:test-token');
        $app['config']->set('threadlive.chat_id', '-1001234567890');
        $app['config']->set('threadlive.webhook_secret', 'webhook-secret');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
