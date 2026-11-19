<?php

beforeEach(fn () => cleanInstall());
afterEach(fn () => cleanInstall());

it('publishes the config pointed at the app\'s ChatHost, the migration and the ChatHost class', function () {
    $this->artisan('threadlive:install', ['--no-migrate' => true])->assertSuccessful();

    $config = file_get_contents(config_path('threadlive.php'));
    expect($config)->toContain("'host' => App\\Threadlive\\ChatHost::class,")
        ->and($config)->not->toContain('GuardChatHost')
        ->and(app_path('Threadlive/ChatHost.php'))->toBeFile()
        ->and(file_get_contents(app_path('Threadlive/ChatHost.php')))->toContain('class ChatHost extends GuardChatHost')
        ->and(glob(database_path('migrations/*_create_threadlive_tables.php')))->toHaveCount(1);
});

it('keeps what is already there, and never publishes the migration twice', function () {
    $this->artisan('threadlive:install', ['--no-migrate' => true])->assertSuccessful();
    file_put_contents(app_path('Threadlive/ChatHost.php'), '<?php // adapted by the app');

    $this->artisan('threadlive:install', ['--no-migrate' => true])
        ->expectsOutputToContain('exists, kept')
        ->assertSuccessful();

    expect(file_get_contents(app_path('Threadlive/ChatHost.php')))->toBe('<?php // adapted by the app')
        ->and(glob(database_path('migrations/*_create_threadlive_tables.php')))->toHaveCount(1);
});

it('adds the .env keys once, with a generated webhook secret', function () {
    $env = base_path('.env');
    $hadEnv = is_file($env);
    $original = $hadEnv ? file_get_contents($env) : null;
    file_put_contents($env, "APP_NAME=Test\n");

    try {
        $this->artisan('threadlive:install', ['--no-migrate' => true])->assertSuccessful();
        $this->artisan('threadlive:install', ['--no-migrate' => true])->assertSuccessful();

        $contents = (string) file_get_contents($env);
        expect(substr_count($contents, 'THREADLIVE_BOT_TOKEN='))->toBe(1)
            ->and($contents)->toContain('THREADLIVE_ENABLED=false')
            ->and($contents)->toMatch('/^THREADLIVE_WEBHOOK_SECRET=[A-Za-z0-9]{48}$/m');
    } finally {
        $hadEnv ? file_put_contents($env, (string) $original) : unlink($env);
    }
});

it('warns when CORS does not cover the routes, and when the queue is sync', function () {
    config()->set('cors.paths', ['api/*']);
    config()->set('queue.default', 'sync');

    $this->artisan('threadlive:install', ['--no-migrate' => true])
        ->expectsOutputToContain("add 'threadlive/*' to `paths` in config/cors.php")
        ->expectsOutputToContain('QUEUE_CONNECTION is sync')
        ->assertSuccessful();
});

it('says nothing about CORS when the routes are covered', function () {
    config()->set('cors.paths', ['api/*', 'threadlive/*']);
    config()->set('queue.default', 'database');

    $this->artisan('threadlive:install', ['--no-migrate' => true])
        ->doesntExpectOutputToContain('CORS does not cover')
        ->assertSuccessful();
});
