<?php

use Illuminate\Support\Carbon;
use Threadlive\Support\WorkingHours;

it('is open between the hours, in their time zone', function (string $utc, bool $open) {
    $hours = new WorkingHours('09:00', '23:00', 'Europe/Nicosia'); // UTC+2 in November

    expect($hours->isOpen(Carbon::parse($utc, 'UTC')))->toBe($open);
})->with([
    'before opening' => ['2026-11-18 06:59', false],
    'at opening' => ['2026-11-18 07:00', true],
    'midday' => ['2026-11-18 12:00', true],
    'at closing' => ['2026-11-18 21:00', false],
]);

it('handles hours past midnight', function (string $utc, bool $open) {
    $hours = new WorkingHours('18:00', '02:00', 'UTC');

    expect($hours->isOpen(Carbon::parse($utc, 'UTC')))->toBe($open);
})->with([
    'evening' => ['2026-11-18 20:00', true],
    'after midnight' => ['2026-11-19 01:30', true],
    'morning' => ['2026-11-19 09:00', false],
]);

it('is always open when the hours start and end together', function () {
    expect((new WorkingHours('00:00', '00:00', 'UTC'))->isOpen(Carbon::parse('2026-11-18 03:00')))->toBeTrue();
});

it('reads the config, padding single-digit hours', function () {
    config()->set('threadlive.hours', ['from' => '9:00', 'to' => '23:00', 'tz' => 'UTC']);

    expect(WorkingHours::fromConfig()->toArray())->toBe(['from' => '09:00', 'to' => '23:00', 'tz' => 'UTC']);
});
