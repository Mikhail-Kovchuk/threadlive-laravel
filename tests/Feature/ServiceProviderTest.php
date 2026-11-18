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
        ->and(config('threadlive.poll_ms'))->toBe(['open' => 3000, 'closed' => 30000]);
});

it('publishes the config and the migration under their tags', function () {
    foreach (['threadlive-config' => 'threadlive.php', 'threadlive-migrations' => 'create_threadlive_tables.php'] as $tag => $file) {
        $paths = ServiceProvider::pathsToPublish(ThreadliveServiceProvider::class, $tag);

        expect($paths)->toHaveCount(1)
            ->and(array_keys($paths)[0])->toBeFile()
            ->and(array_values($paths)[0])->toEndWith($file);
    }
});

it('refuses a host class that is not a ChatHost, naming the setting', function () {
    config()->set('threadlive.host', stdClass::class);

    expect(fn () => app(ChatHost::class))->toThrow(InvalidArgumentException::class, 'threadlive.host');
});
