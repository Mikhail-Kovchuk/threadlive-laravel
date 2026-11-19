<?php

use Threadlive\Host\GuardChatHost;
use Threadlive\Support\ConfigPublisher;

beforeEach(fn () => cleanInstall());
afterEach(function () {
    cleanInstall();
    @unlink(config_path('threadlive.php.bak'));
});

it('creates config/threadlive.php from the package\'s, comments and all', function () {
    $this->artisan('threadlive:config')->expectsOutputToContain('published')->assertSuccessful();

    $config = file_get_contents(config_path('threadlive.php'));
    expect($config)->toBe(file_get_contents(__DIR__.'/../../config/threadlive.php'))
        ->and((require config_path('threadlive.php'))['close_after_hours'])->toBe(24);
});

it('points the config at the app\'s ChatHost once there is one', function () {
    mkdir(app_path('Threadlive'), 0777, true);
    file_put_contents(app_path('Threadlive/ChatHost.php'), '<?php');

    $this->artisan('threadlive:config')->assertSuccessful();

    expect(file_get_contents(config_path('threadlive.php')))
        ->toContain("'host' => App\\Threadlive\\ChatHost::class,")
        ->not->toContain('GuardChatHost');
});

it('adds only the settings a package update brought, keeping the values changed', function () {
    // As published by an older version: a changed value, a setting since
    // removed, and no `topics` or `commands` yet.
    file_put_contents(config_path('threadlive.php'), <<<'PHP'
        <?php

        return [

            // Changed by the application.
            'close_after_hours' => 48,

            'legacy_setting' => true,

        ];
        PHP);

    $this->artisan('threadlive:config')
        ->expectsOutputToContain('added enabled, bot_token')
        ->expectsOutputToContain('Not settings of this package version (safe to remove): legacy_setting')
        ->assertSuccessful();

    $source = file_get_contents(config_path('threadlive.php'));
    $config = require config_path('threadlive.php');
    $defaults = require __DIR__.'/../../config/threadlive.php';

    expect($config['close_after_hours'])->toBe(48)
        ->and($config['legacy_setting'])->toBeTrue()
        ->and(array_keys(array_diff_key($defaults, $config)))->toBe([])
        ->and($config['topics'])->toBe([])
        ->and($config['host'])->toBe(GuardChatHost::class)
        // The comment travels with its setting.
        ->and($source)->toContain("// Buttons on the widget's first screen")
        ->and($source)->toContain('// Changed by the application.');

    $this->artisan('threadlive:config')->expectsOutputToContain('up to date, kept')->assertSuccessful();
});

it('starts over with --force, keeping the old file', function () {
    file_put_contents(config_path('threadlive.php'), "<?php\n\nreturn ['close_after_hours' => 48];\n");

    $this->artisan('threadlive:config', ['--force' => true])->assertSuccessful();

    expect((require config_path('threadlive.php'))['close_after_hours'])->toBe(24)
        ->and(file_get_contents(config_path('threadlive.php.bak')))->toContain('48');
});

it('leaves a broken file alone, saying so', function () {
    file_put_contents(config_path('threadlive.php'), "<?php\n\nreturn 'oops';\n");

    $this->artisan('threadlive:config')->expectsOutputToContain('does not return an array')->assertFailed();

    expect(file_get_contents(config_path('threadlive.php')))->toContain('oops');
});

it('finds every top-level setting of the package config, with its comment', function () {
    $blocks = ConfigPublisher::blocks(file_get_contents(__DIR__.'/../../config/threadlive.php'));
    $defaults = require __DIR__.'/../../config/threadlive.php';

    expect(array_keys($blocks))->toBe(array_keys($defaults))
        ->and($blocks['poll_ms'])->toContain("'idle_after' => 300000")
        ->and($blocks['live_hours'])->toContain('// A conversation is live');
});

it('keeps .env to what differs between environments, or is secret', function () {
    $source = file_get_contents(__DIR__.'/../../config/threadlive.php');
    preg_match_all("/env\\('([A-Z_]+)'/", $source, $m);

    expect($m[1])->toBe([
        'THREADLIVE_ENABLED',
        'THREADLIVE_BOT_TOKEN',
        'THREADLIVE_GROUP_ID',
        'THREADLIVE_WEBHOOK_SECRET',
        'THREADLIVE_TURNSTILE_SECRET',
        'THREADLIVE_QUEUE_CONNECTION',
        'THREADLIVE_TRANSLATION_KEY',
    ]);
});
