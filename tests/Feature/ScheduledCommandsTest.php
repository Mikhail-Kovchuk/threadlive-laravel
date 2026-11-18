<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;

/** A conversation in a topic, as if it had gone through delivery. */
function conversation(array $attributes = []): Conversation
{
    return Conversation::query()->create([
        'public_id' => (string) Str::uuid(),
        'name' => 'Anna',
        'telegram_thread_id' => 300 + Conversation::query()->count(),
        'topic_state' => TopicState::Open,
        'last_activity_at' => now(),
        'last_visitor_message_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function () {
    fakeTelegram();
    config()->set('threadlive.hours', ['from' => '00:00', 'to' => '00:00', 'tz' => 'UTC']); // always open
});

it('is scheduled by the package itself', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

    expect($commands)->toContain('threadlive:maintain')->toContain('threadlive:prune');
});

it('closes conversations idle for close_after_hours, telling the visitor', function () {
    $idle = conversation(['last_activity_at' => now()->subHours(25), 'status' => ConversationStatus::Answered]);
    $recent = conversation(['last_activity_at' => now()->subHours(2), 'status' => ConversationStatus::Answered]);

    $this->artisan('threadlive:maintain')->assertSuccessful();

    expect($idle->refresh()->status)->toBe(ConversationStatus::Closed)
        ->and($idle->closed_by)->toBe('auto')
        ->and($idle->messages()->where('direction', Direction::System)->value('body'))->toBe('Conversation closed')
        ->and($recent->refresh()->status)->toBe(ConversationStatus::Answered);
});

it('deletes topics closed for delete_topic_after_days, keeping the history', function () {
    $old = conversation(['status' => ConversationStatus::Closed, 'topic_state' => TopicState::Closed, 'topic_closed_at' => now()->subDays(8)]);
    $old->messages()->create(['direction' => Direction::Visitor, 'body' => 'Kept']);
    $fresh = conversation(['status' => ConversationStatus::Closed, 'topic_state' => TopicState::Closed, 'topic_closed_at' => now()->subDays(2)]);

    $this->artisan('threadlive:maintain')->assertSuccessful();

    expect(telegramCalls('deleteForumTopic'))->toHaveCount(1)
        ->and(telegramCalls('deleteForumTopic')[0]['message_thread_id'])->toBe($old->telegram_thread_id)
        ->and($old->refresh()->topic_state)->toBe(TopicState::Deleted)
        ->and($old->messages()->count())->toBe(1)
        ->and($fresh->refresh()->topic_state)->toBe(TopicState::Closed);
});

it('alerts General once per wait, with sound, within support hours only', function () {
    $waiting = conversation(['status' => ConversationStatus::Waiting, 'last_visitor_message_at' => now()->subMinutes(12)]);
    conversation(['status' => ConversationStatus::Waiting, 'last_visitor_message_at' => now()->subMinutes(3)]);

    $this->artisan('threadlive:maintain')->assertSuccessful();
    $this->artisan('threadlive:maintain')->assertSuccessful();

    $alerts = collect(telegramCalls('sendMessage'))->filter(fn (array $m) => str_contains($m['text'], 'is waiting'));
    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['text'])->toContain('Anna</a> is waiting 12m')
        ->and($alerts->first()['text'])->toContain("https://t.me/c/1234567890/{$waiting->telegram_thread_id}")
        ->and($alerts->first()['disable_notification'])->toBeFalse()
        ->and($waiting->refresh()->waiting_alert_sent_at)->not->toBeNull();
});

it('sends no waiting alerts outside support hours', function () {
    config()->set('threadlive.hours', ['from' => '09:00', 'to' => '10:00', 'tz' => 'UTC']);
    $this->travelTo(now()->setTime(22, 0));
    conversation(['status' => ConversationStatus::Waiting, 'last_visitor_message_at' => now()->subMinutes(30)]);

    $this->artisan('threadlive:maintain')->assertSuccessful();

    expect(collect(telegramCalls('sendMessage'))->filter(fn (array $m) => str_contains($m['text'], 'is waiting')))->toBeEmpty();
});

it('posts the dashboard once, then edits it in place', function () {
    conversation(['status' => ConversationStatus::Answered, 'assignee_name' => 'Maria']);

    $this->artisan('threadlive:maintain')->assertSuccessful();
    $this->artisan('threadlive:maintain')->assertSuccessful();

    $posted = collect(telegramCalls('sendMessage'))->filter(fn (array $m) => str_contains($m['text'], 'Waiting for a reply'));
    expect($posted)->toHaveCount(1)
        ->and($posted->first()['text'])->toContain('💬 In progress (1): Maria — 1')
        ->and(telegramCalls('editMessageText'))->toHaveCount(1)
        ->and(telegramCalls('editMessageText')[0]['message_id'])->toBe(1001);
});

it('does nothing while Threadlive is off', function () {
    config()->set('threadlive.enabled', false);
    conversation(['last_activity_at' => now()->subDays(3)]);

    $this->artisan('threadlive:maintain')->assertSuccessful();

    expect(Http::recorded())->toBeEmpty();
});

it('prunes conversations past retention: messages, files and topic', function () {
    Storage::fake('local');
    Storage::disk('local')->put('threadlive/shot.png', 'png');
    $old = conversation(['last_activity_at' => now()->subDays(91)]);
    $old->messages()->create([
        'direction' => Direction::Visitor,
        'body' => 'See attached',
        'attachments' => [['disk' => 'local', 'path' => 'threadlive/shot.png', 'name' => 'shot.png']],
    ]);
    $kept = conversation(['last_activity_at' => now()->subDays(10)]);

    $this->artisan('threadlive:prune')->assertSuccessful();

    expect(Conversation::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(Message::query()->where('conversation_id', $old->id)->count())->toBe(0)
        ->and(Storage::disk('local')->exists('threadlive/shot.png'))->toBeFalse()
        ->and(telegramCalls('deleteForumTopic')[0]['message_thread_id'])->toBe($old->telegram_thread_id);
});

it('keeps everything when retention is off', function () {
    config()->set('threadlive.retention_days', 0);
    conversation(['last_activity_at' => now()->subYears(2)]);

    $this->artisan('threadlive:prune')->assertSuccessful();

    expect(Conversation::query()->count())->toBe(1);
});

it('shows the webhook state and the counts', function () {
    fakeTelegram(['getWebhookInfo' => [
        'url' => 'https://support.example.com/threadlive/telegram/webhook',
        'pending_update_count' => 2,
        'last_error_message' => 'Connection refused',
        'last_error_date' => 1763460000,
    ]]);
    conversation(['status' => ConversationStatus::Waiting]);

    $this->artisan('threadlive:status')
        ->expectsOutputToContain('https://support.example.com/threadlive/telegram/webhook')
        ->expectsOutputToContain('Connection refused')
        ->assertSuccessful();
});

it('opens, writes in and closes a test topic', function () {
    $this->artisan('threadlive:test')->assertSuccessful();

    expect(telegramCalls('createForumTopic')[0]['name'])->toBe('🧪 Threadlive test')
        ->and(telegramCalls('closeForumTopic'))->toHaveCount(1);
});
