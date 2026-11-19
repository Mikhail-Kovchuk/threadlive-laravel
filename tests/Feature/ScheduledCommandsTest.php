<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Delivery;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Mail\UnreadReplies;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Telegram\Dashboard;

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

it('counts "closed today" by support\'s day when the app keeps another time zone', function () {
    $default = date_default_timezone_get();
    date_default_timezone_set('Europe/Kyiv'); // UTC+2 in November, as the app would boot with
    config()->set('app.timezone', 'Europe/Kyiv');

    try {
        // Support's day is UTC: 2026-11-18 starts at 02:00 Kyiv time.
        $this->travelTo(Carbon\Carbon::parse('2026-11-18 23:00', 'Europe/Kyiv'));
        conversation(['status' => ConversationStatus::Closed, 'closed_at' => Carbon\Carbon::parse('2026-11-18 01:00', 'Europe/Kyiv')]);
        conversation(['status' => ConversationStatus::Closed, 'closed_at' => Carbon\Carbon::parse('2026-11-18 03:00', 'Europe/Kyiv')]);

        expect(app(Dashboard::class)->html())->toContain('✅ Closed today: 1');
    } finally {
        date_default_timezone_set($default);
    }
});

it('lists what could not be delivered on the dashboard, and sends it again on request', function () {
    Queue::fake();
    $anna = conversation(['name' => 'Anna']);
    $john = conversation(['name' => 'John', 'telegram_thread_id' => null]);
    $anna->messages()->create(['direction' => Direction::Visitor, 'body' => 'One', 'delivery' => Delivery::Failed]);
    $anna->messages()->create(['direction' => Direction::Visitor, 'body' => 'Two', 'delivery' => Delivery::Failed]);
    $john->messages()->create(['direction' => Direction::Visitor, 'body' => 'Three', 'delivery' => Delivery::Failed]);
    $john->messages()->create(['direction' => Direction::Visitor, 'body' => 'Fine', 'delivery' => Delivery::Sent]);

    $html = app(Dashboard::class)->html();
    expect($html)->toContain('⚠️ <b>Not delivered (3)</b>: <a href="https://t.me/c/')
        ->and($html)->toContain("Anna #{$anna->id}</a> — 2, John #{$john->id} — 1");

    $this->artisan('threadlive:retry-failed', ['--conversation' => "#{$anna->id}"])
        ->expectsOutputToContain('Queued 2 messages in 1 conversation.')
        ->assertSuccessful();

    Queue::assertPushed(DeliverVisitorMessages::class, 1);
    Queue::assertPushed(DeliverVisitorMessages::class, fn (DeliverVisitorMessages $job) => $job->conversationId === $anna->id);
    expect(Message::query()->where('delivery', Delivery::Pending)->count())->toBe(2)
        ->and(app(Dashboard::class)->html())->toContain('Not delivered (1)');

    $this->artisan('threadlive:retry-failed')->expectsOutputToContain('Queued 1 message in 1 conversation.');
    expect(app(Dashboard::class)->html())->not->toContain('Not delivered');

    $this->artisan('threadlive:retry-failed')->expectsOutputToContain('Nothing failed');
});

describe('offline e-mail', function () {
    beforeEach(fn () => Mail::fake());

    function answered(array $attributes = []): Conversation
    {
        $conversation = conversation([
            'status' => ConversationStatus::Answered,
            'email' => 'anna@example.com',
            'last_agent_message_at' => now()->subMinutes(20),
            ...$attributes,
        ]);
        $conversation->messages()->create(['direction' => Direction::Visitor, 'body' => 'Hi']);

        return $conversation;
    }

    it('sends the unread replies once, as the visitor would see them, with the site link', function () {
        config()->set('threadlive.email_link', 'https://example.com');
        $conversation = answered(['context' => ['url' => 'https://example.com/records/7'], 'visitor_language' => 'es']);
        $read = $conversation->messages()->create(['direction' => Direction::Agent, 'body' => 'Already seen', 'agent_name' => 'Maria']);
        $conversation->forceFill(['visitor_read_message_id' => $read->id])->save();
        $conversation->messages()->create([
            'direction' => Direction::Agent,
            'body' => 'Your record is ready.',
            'agent_name' => 'Maria',
            'translation' => ['text' => 'Su registro está listo.', 'from' => 'en', 'to' => 'es'],
            'attachments' => [['id' => 'f1', 'disk' => 'local', 'path' => 'x', 'name' => 'record.pdf', 'mime' => 'application/pdf', 'size' => 3]],
        ]);

        $this->artisan('threadlive:maintain')->expectsOutputToContain('1 e-mails')->assertSuccessful();
        $this->artisan('threadlive:maintain')->assertSuccessful();

        Mail::assertSent(UnreadReplies::class, 1);
        Mail::assertSent(UnreadReplies::class, function (UnreadReplies $mail) {
            $html = $mail->render();

            return $mail->hasTo('anna@example.com')
                && $mail->locale === 'es'
                && $mail->replies->count() === 1
                && str_contains($html, 'Su registro está listo.')
                && ! str_contains($html, 'Already seen')
                && str_contains($html, 'Maria')
                && str_contains($html, 'record.pdf')
                && str_contains($html, 'href="http://localhost/threadlive/files/')
                && str_contains($html, 'href="https://example.com/records/7"');
        });
    });

    it('never links a page on another host, however the URL is dressed up', function (string $page, string $expected) {
        config()->set('threadlive.email_link', 'https://example.com');
        $conversation = answered(['context' => ['url' => $page]]);
        $conversation->messages()->create(['direction' => Direction::Agent, 'body' => 'Hello']);

        $this->artisan('threadlive:maintain')->assertSuccessful();

        Mail::assertSent(UnreadReplies::class, fn (UnreadReplies $mail) => str_contains($mail->render(), 'href="'.$expected.'"')
            && ! str_contains($mail->render(), 'evil'));
    })->with([
        'another host' => ['https://evil.example.net/login', 'https://example.com'],
        'a backslash before @' => ['https://evil.example.net\\@example.com/', 'https://example.com/'],
        'userinfo' => ['https://evil.example.net@example.com/records', 'https://example.com/records'],
        'backslashes in the path' => ['https://example.com/\\\\other.example.net/x', 'https://example.com/other.example.net/x'],
    ]);

    it('waits, skips what was read, and starts again with the next reply', function () {
        $soon = answered(['last_agent_message_at' => now()->subMinutes(5)]);
        $soon->messages()->create(['direction' => Direction::Agent, 'body' => 'Too soon']);
        $read = answered();
        $reply = $read->messages()->create(['direction' => Direction::Agent, 'body' => 'Seen']);
        $read->forceFill(['visitor_read_message_id' => $reply->id])->save();
        $noEmail = answered(['email' => null]);
        $noEmail->messages()->create(['direction' => Direction::Agent, 'body' => 'Nowhere to send']);

        $this->artisan('threadlive:maintain')->assertSuccessful();
        Mail::assertNothingSent();

        // A manager answers again in the topic: a new batch.
        telegramUpdate(['message' => managerMessage($read->telegram_thread_id, 'One more thing')]);
        expect($read->refresh()->unread_email_sent_at)->toBeNull();
        $this->travel(16)->minutes();

        $this->artisan('threadlive:maintain')->assertSuccessful();
        Mail::assertSent(UnreadReplies::class, fn (UnreadReplies $mail) => $mail->replies->pluck('body')->all() === ['One more thing']);
    });

    it('is off at 0', function () {
        config()->set('threadlive.email_unread_after_minutes', 0);
        answered()->messages()->create(['direction' => Direction::Agent, 'body' => 'Hello']);

        $this->artisan('threadlive:maintain')->assertSuccessful();

        Mail::assertNothingSent();
    });
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
