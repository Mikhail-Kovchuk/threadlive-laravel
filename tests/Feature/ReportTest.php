<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Threadlive\Enums\Direction;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\Report;
use Threadlive\ThreadliveServiceProvider;

beforeEach(function () {
    Queue::fake();
    config()->set('threadlive.hours', ['from' => '09:00', 'to' => '18:00', 'tz' => 'UTC']);
    $this->travelTo(now()->setDate(2026, 11, 27)->setTime(12, 0));
});

/** A conversation started $minutesAgo, with what happened in it. */
function reported(int $minutesAgo, array $attributes = [], array $replies = []): Conversation
{
    $conversation = Conversation::query()->create([
        'public_id' => (string) Str::uuid(),
        'name' => 'Anna',
        ...$attributes,
    ]);
    $conversation->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

    foreach ($replies as [$after, $name, $ai]) {
        $message = $conversation->messages()->create([
            'direction' => Direction::Agent,
            'body' => 'Reply',
            'agent_name' => $name,
            'by_assistant' => $ai,
        ]);
        $message->forceFill(['created_at' => now()->subMinutes($minutesAgo)->addMinutes($after)])->save();
    }

    return $conversation;
}

it('counts the week: conversations, the assistant, first replies, managers, ratings', function () {
    // Answered by Maria after 4 min, rated 👍.
    reported(600, ['rating' => 'good', 'rated_at' => now()->subHour()], [[4, 'Maria', false]]);
    // Answered by Oleh after 10 min, rated 👎 with a comment.
    reported(500, ['rating' => 'bad', 'rating_comment' => 'Too slow.', 'rated_at' => now()->subHour()], [[10, 'Oleh', false]]);
    // The assistant alone.
    reported(400, ['assistant_replies' => 2], [[0, 'Ava', true]]);
    // The assistant, then handed over after 20 min, Maria 6 min after that.
    $handed = reported(300, ['assistant_replies' => 1], [[0, 'Ava', true], [26, 'Maria', false]]);
    $handed->forceFill(['handed_off_at' => now()->subMinutes(300)->addMinutes(20)])->save();
    // Nobody answered.
    reported(200);
    // Last month: left out.
    reported(60 * 24 * 30, ['rating' => 'bad', 'rated_at' => now()->subDays(30)], [[1, 'Maria', false]]);

    $html = app(Report::class)->html(now()->subDays(7), now());

    expect($html)->toContain('📊 <b>Report</b> · 20–27 Nov 2026')
        ->and($html)->toContain('💬 Conversations: 5 new · 0 closed')
        ->and($html)->toContain('🤖 Assistant: 2 — 1 without a manager, 1 passed on')
        ->and($html)->toContain('⚠️ No reply at all: 1')
        ->and($html)->toContain('⏱ A manager\'s first reply: 6 min typically (median of 3)')
        ->and($html)->toContain('👥 Answered: Maria 2 · Oleh 1')
        ->and($html)->toContain('⭐ Ratings: 👍 1 · 👎 1 (50% good)')
        ->and($html)->toContain('👎 #2 Anna: “Too slow.”')
        ->and($html)->not->toContain('Ava');
});

it('says only what there is in a quiet week', function () {
    $html = app(Report::class)->html(now()->subDays(7), now());

    expect($html)->toContain('Conversations: 0 new · 0 closed')
        ->and($html)->not->toContain('Ratings')
        ->and($html)->not->toContain('Answered');
});

it('posts it in General, or prints it', function () {
    fakeTelegram();
    reported(60);

    $this->artisan('threadlive:report')->assertSuccessful();
    $post = telegramCalls('sendMessage')[0];
    expect($post)->not->toHaveKey('message_thread_id') // General
        ->and($post['disable_notification'])->toBeTrue()
        ->and($post['text'])->toContain('Conversations: 1 new');

    $this->artisan('threadlive:report', ['--print' => true, '--days' => 1])
        ->expectsOutputToContain('Report · 26–27 Nov 2026')
        ->assertSuccessful();
    expect(telegramCalls('sendMessage'))->toHaveCount(1);
});

it('is scheduled weekly as the config says, in the support hours\' zone — or not at all', function () {
    config()->set('threadlive.report', ['weekly' => true, 'day' => 'friday', 'at' => '17:30']);
    config()->set('threadlive.hours.tz', 'Europe/Kyiv');

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'threadlive:report'));
    expect($event->expression)->toBe('30 17 * * 5')
        ->and($event->timezone)->toBe('Europe/Kyiv');

    config()->set('threadlive.report.weekly', false);
    expect(ThreadliveServiceProvider::weeklyReport())->toBeNull();
});
