<?php

use Illuminate\Support\ServiceProvider;
use Threadlive\Contracts\ChatHost;
use Threadlive\Host\GuardChatHost;
use Threadlive\ThreadliveServiceProvider;

it('ships safe defaults: off until set up, the guard-reading host', function () {
    $defaults = require __DIR__.'/../../config/threadlive.php';

    expect($defaults['enabled'])->toBeFalse()
        ->and($defaults['host'])->toBe(GuardChatHost::class)
        ->and($defaults['route_prefix'])->toBe('threadlive')
        ->and($defaults['middleware'])->toBe(['api']);
});

it('merges the config, so an unpublished one still works', function () {
    expect(config('threadlive.hours'))->toBe(['from' => '09:00', 'to' => '23:00', 'tz' => 'UTC'])
        ->and(config('threadlive.poll_ms'))->toBe(['open' => 3000, 'closed' => 30000, 'idle' => 15000, 'idle_after' => 300000]);
});

it('publishes the config and the migrations under their tags', function () {
    $config = ServiceProvider::pathsToPublish(ThreadliveServiceProvider::class, 'threadlive-config');
    expect($config)->toHaveCount(1)
        ->and(array_keys($config)[0])->toBeFile()
        ->and(array_values($config)[0])->toEndWith('threadlive.php');

    // Every migration of the package, the first one first.
    $migrations = ServiceProvider::pathsToPublish(ThreadliveServiceProvider::class, 'threadlive-migrations');
    expect(count($migrations))->toBe(count(glob(__DIR__.'/../../database/migrations/*.php') ?: []))
        ->and(array_values($migrations)[0])->toEndWith('create_threadlive_tables.php');
    foreach (array_keys($migrations) as $source) {
        expect($source)->toBeFile();
    }
});

it('refuses a host class that is not a ChatHost, naming the setting', function () {
    config()->set('threadlive.host', stdClass::class);

    expect(fn () => app(ChatHost::class))->toThrow(InvalidArgumentException::class, 'threadlive.host');
});
