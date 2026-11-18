<?php

use Illuminate\Support\ServiceProvider;
use Threadlive\ThreadliveServiceProvider;

it('merges the default config, so an unpublished one still works', function () {
    expect(config('threadlive.enabled'))->toBeFalse()
        ->and(config('threadlive.route_prefix'))->toBe('threadlive')
        ->and(config('threadlive.middleware'))->toBe(['api'])
        ->and(config('threadlive.hours'))->toBe(['from' => '09:00', 'to' => '23:00', 'tz' => 'UTC'])
        ->and(config('threadlive.poll_ms'))->toBe(['open' => 3000, 'closed' => 30000]);
});

it('publishes the config under the threadlive-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(ThreadliveServiceProvider::class, 'threadlive-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('threadlive.php'))
        ->and(array_keys($paths)[0])->toBeFile();
});
