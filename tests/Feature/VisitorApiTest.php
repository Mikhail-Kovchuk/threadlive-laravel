<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Threadlive\ChatUser;
use Threadlive\Enums\Direction;
use Threadlive\Events\ConversationStarted;
use Threadlive\Events\VisitorMessageReceived;
use Threadlive\Models\Conversation;
use Threadlive\Tests\Fixtures\FakeChatHost;

describe('bootstrap', function () {
    it('tells a new guest everything the widget needs', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00', 'UTC'));

        $this->getJson('/threadlive/bootstrap')->assertOk()->assertExactJson([
            'enabled' => true,
            'online' => true,
            'hours' => ['from' => '09:00', 'to' => '23:00', 'tz' => 'UTC'],
            'can_write' => true,
            'poll_ms' => ['open' => 3000, 'closed' => 30000, 'idle' => 15000, 'idle_after' => 300000],
            'topics' => [],
            'languages' => ['en'],
            'faq' => [],
            'max_message_length' => 2000,
            'fallback' => null,
            'attachments' => [
                'enabled' => true,
                'max_kb' => 10240,
                'max_files' => 5,
                'accept' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'],
            ],
            'agent_display_name' => 'Support',
            'turnstile_site_key' => null,
            'conversation' => null,
        ]);
    });

    it('serves the first screen\'s topics from the config, so changing them needs no frontend release', function () {
        config()->set('threadlive.topics', ['Billing', '  ', 'Something else', 42]);

        $this->getJson('/threadlive/bootstrap')->assertJsonPath('topics', ['Billing', 'Something else']);
    });

    it('serves the header\'s fallback link from the config, and only a safe one', function () {
        config()->set('threadlive.fallback', ['url' => 'https://t.me/acme_support', 'label' => 'Write to us in Telegram']);
        $this->getJson('/threadlive/bootstrap')->assertJsonPath('fallback', ['url' => 'https://t.me/acme_support', 'label' => 'Write to us in Telegram']);

        config()->set('threadlive.fallback', ['url' => 'mailto:help@acme.test', 'label' => null]);
        $this->getJson('/threadlive/bootstrap')->assertJsonPath('fallback', ['url' => 'mailto:help@acme.test', 'label' => null]);

        config()->set('threadlive.fallback', ['url' => 'javascript:alert(1)', 'label' => 'x']);
        $this->getJson('/threadlive/bootstrap')->assertJsonPath('fallback', null);
    });

    it('is offline outside support hours, in their time zone', function () {
        config()->set('threadlive.hours', ['from' => '09:00', 'to' => '18:00', 'tz' => 'Europe/Nicosia']);
        Carbon::setTestNow(Carbon::parse('2026-11-18 17:30', 'UTC')); // 19:30 in Nicosia

        $this->getJson('/threadlive/bootstrap')->assertJsonPath('online', false);
    });

    it('says when the chat is off, and the other routes do not exist then', function () {
        config()->set('threadlive.enabled', false);

        $this->getJson('/threadlive/bootstrap')->assertOk()->assertJsonPath('enabled', false);
        sendMessage('Hello')->assertNotFound();
    });

    it('marks a conversation live only while it is open and recent', function () {
        $token = sendMessage('Hello')->json('visitor_token');
        $headers = ['X-Threadlive-Token' => $token];
        $conversation = Conversation::query()->firstOrFail();

        $this->getJson('/threadlive/bootstrap', $headers)->assertJsonPath('conversation.active', true);

        $conversation->forceFill(['last_activity_at' => now()->subHours(25)])->save();
        $this->getJson('/threadlive/bootstrap', $headers)->assertJsonPath('conversation.active', false);

        $conversation->forceFill(['last_activity_at' => now(), 'status' => 'closed'])->save();
        $this->getJson('/threadlive/bootstrap', $headers)->assertJsonPath('conversation.active', false);
    });

    it('does not let guests write when guests are not allowed', function () {
        config()->set('threadlive.allow_guests', false);

        $this->getJson('/threadlive/bootstrap')->assertJsonPath('can_write', false);
        sendMessage('Hello')->assertForbidden()->assertJsonPath('message', 'Sign in to write to us.');
    });
});

describe('a guest conversation', function () {
    it('starts with the first message, which returns the guest token once', function () {
        Event::fake([ConversationStarted::class, VisitorMessageReceived::class]);

        $first = sendMessage('Hi! Is the withdrawal still pending?')
            ->assertCreated()
            ->assertJsonPath('message.direction', 'visitor')
            ->assertJsonPath('message.body', 'Hi! Is the withdrawal still pending?')
            ->assertJsonPath('context', null)
            ->assertJsonPath('conversation', ['status' => 'waiting', 'unread' => 0, 'email' => null, 'active' => true]);

        $token = $first->json('visitor_token');
        expect($token)->toBeString()->toHaveLength(48);

        sendMessage('One more thing', $token)->assertCreated()->assertJsonMissingPath('visitor_token');

        expect(Conversation::query()->count())->toBe(1)
            ->and(Conversation::query()->first()?->visitor_token_hash)->toBe(hash('sha256', $token));
        Event::assertDispatchedTimes(ConversationStarted::class, 1);
        Event::assertDispatchedTimes(VisitorMessageReceived::class, 2);
    });

    it('stores a retried send once, answering with the stored message', function () {
        $id = (string) Str::uuid();
        $token = sendMessage('Hello', null, ['client_msg_id' => Str::uuid()->toString()])->json('visitor_token');

        $first = sendMessage('Again', $token, ['client_msg_id' => $id])->assertCreated();
        $retry = sendMessage('Again', $token, ['client_msg_id' => $id])->assertOk();

        expect($retry->json('message.id'))->toBe($first->json('message.id'))
            ->and(Conversation::query()->first()?->messages()->count())->toBe(2);
    });

    it('returns the history, then only what is new', function () {
        $token = sendMessage('First')->json('visitor_token');
        $second = sendMessage('Second', $token)->json('message.id');

        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.body', 'First');

        $this->getJson('/threadlive/messages?after='.$second, ['X-Threadlive-Token' => $token])
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    });

    it('never serves internal notes to the visitor', function () {
        $token = sendMessage('Hello')->json('visitor_token');
        $conversation = Conversation::query()->firstOrFail();
        $conversation->messages()->create(['direction' => Direction::Note, 'body' => '// refund approved, tell her tomorrow']);
        $conversation->messages()->create(['direction' => Direction::Agent, 'body' => 'Checking now.', 'agent_name' => 'Maria']);

        $bodies = collect($this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->json('messages'))->pluck('body');

        expect($bodies->all())->toBe(['Hello', 'Checking now.']);
    });

    it('answers 404 for a token with no conversation', function () {
        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => 'made-up'])->assertNotFound();
        $this->getJson('/threadlive/messages')->assertNotFound();
    });

    it('reopens a closed conversation when the visitor writes again', function () {
        $token = sendMessage('Hello')->json('visitor_token');
        Conversation::query()->firstOrFail()->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => 'auto'])->save();

        sendMessage('Back again', $token)->assertJsonPath('conversation.status', 'waiting');

        expect(Conversation::query()->firstOrFail()->closed_at)->toBeNull();
    });

    it('limits how many conversations one address may start', function () {
        foreach (range(1, 5) as $i) {
            sendMessage("Hello {$i}")->assertCreated();
        }

        sendMessage('Hello 6')->assertTooManyRequests();
    });
});

describe('validation', function () {
    it('explains a message that is too long, in words the visitor sees as they are', function () {
        sendMessage(str_repeat('a', 2001))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The message may not be longer than 2000 characters.');
    });

    it('refuses an empty message', function () {
        sendMessage('   ')->assertUnprocessable()->assertJsonPath('message', 'Write a message first.');
    });
});

describe('limits', function () {
    it('slows down one conversation sending too fast, and no one else', function () {
        $token = sendMessage('1')->json('visitor_token');
        foreach (range(2, 15) as $n) {
            sendMessage((string) $n, $token)->assertCreated();
        }

        sendMessage('16', $token)->assertTooManyRequests()
            ->assertJsonPath('message', 'You are sending messages too fast. Wait a moment and send again.');
        sendMessage('Someone else')->assertCreated();
    });

    it('does not give a new allowance to a new made-up token on every request', function () {
        foreach (range(1, 600) as $n) {
            $this->getJson('/threadlive/bootstrap', ['X-Threadlive-Token' => "made-up-{$n}"]);
        }

        $this->getJson('/threadlive/bootstrap', ['X-Threadlive-Token' => 'made-up-again'])->assertTooManyRequests();
    });

    it('keeps an odd, long Accept-Language within the column', function () {
        sendMessage('Hello', null, [])->assertCreated();
        $this->postJson('/threadlive/messages', ['body' => 'Hi', 'client_msg_id' => (string) Str::uuid()], [
            'Accept-Language' => 'sr-Latn-RS-x-private-extension-tag',
        ])->assertCreated();

        expect(mb_strlen((string) Conversation::query()->latest('id')->firstOrFail()->locale))->toBeLessThanOrEqual(16);
    });
});

describe('unread and e-mail', function () {
    it('counts agent replies until the visitor has read them', function () {
        $token = sendMessage('Hello')->json('visitor_token');
        $reply = Conversation::query()->firstOrFail()->messages()->create([
            'direction' => Direction::Agent, 'body' => 'Hi!', 'agent_name' => 'Maria',
        ]);

        $headers = ['X-Threadlive-Token' => $token];
        $this->getJson('/threadlive/bootstrap', $headers)->assertJsonPath('conversation.unread', 1);

        $this->postJson('/threadlive/read', ['message_id' => $reply->id], $headers)->assertNoContent();

        $this->getJson('/threadlive/bootstrap', $headers)->assertJsonPath('conversation.unread', 0);
    });

    it('shows the agent name only when the config says so', function () {
        $token = sendMessage('Hello')->json('visitor_token');
        Conversation::query()->firstOrFail()->messages()->create([
            'direction' => Direction::Agent, 'body' => 'Hi!', 'agent_name' => 'Maria',
        ]);
        $headers = ['X-Threadlive-Token' => $token];

        $this->getJson('/threadlive/messages', $headers)->assertJsonPath('messages.1.author', 'Maria');

        config()->set('threadlive.show_agent_name', false);
        $this->getJson('/threadlive/messages', $headers)->assertJsonPath('messages.1.author', null);
    });

    it('keeps the e-mail for replies sent while the visitor is away', function () {
        $token = sendMessage('Hello')->json('visitor_token');
        $headers = ['X-Threadlive-Token' => $token];

        $this->postJson('/threadlive/email', ['email' => 'not an address'], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Enter a valid e-mail address.');
        $this->postJson('/threadlive/email', ['email' => 'anna@example.com'], $headers)->assertNoContent();

        $this->getJson('/threadlive/bootstrap', $headers)->assertJsonPath('conversation.email', 'anna@example.com');
    });
});

describe('a signed-in visitor', function () {
    it('is known by the host, and needs no token', function () {
        FakeChatHost::$user = new ChatUser('42', 'Anna', 'anna@example.com');

        sendMessage('Hello')->assertCreated()->assertJsonMissingPath('visitor_token');

        $conversation = Conversation::query()->firstOrFail();
        expect($conversation->user_ref)->toBe('42')
            ->and($conversation->email)->toBe('anna@example.com');
        $this->getJson('/threadlive/messages')->assertOk()->assertJsonCount(1, 'messages');
    });

    it('takes their guest conversation along on signing in', function () {
        $token = sendMessage('Written as a guest')->json('visitor_token');

        FakeChatHost::$user = new ChatUser('42', 'Anna');
        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Written as a guest');

        expect(Conversation::query()->firstOrFail()->user_ref)->toBe('42');
    });

    it('does not hand an old guest chat to whoever signs in next on that browser', function () {
        $token = sendMessage('Written as a guest, yesterday')->json('visitor_token');
        $this->travel(3)->hours();

        FakeChatHost::$user = new ChatUser('42', 'Anna');
        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertNotFound();

        expect(Conversation::query()->firstOrFail()->user_ref)->toBeNull();
    });

    it('leaves nothing behind for the next guest with the same token', function () {
        $token = sendMessage('Private question')->json('visitor_token');
        FakeChatHost::$user = new ChatUser('42');
        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertOk();

        FakeChatHost::$user = null; // signed out, same browser

        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertNotFound();
    });
});

describe('context cards', function () {
    beforeEach(function () {
        FakeChatHost::$contextLine = function (array $client): ?string {
            $order = $client['attributes']['order'] ?? null;

            return is_string($order) ? "About order {$order}" : null;
        };
    });

    it('stores a card before the message when the subject changes, and returns it', function () {
        $about = fn (string $order) => ['context' => ['url' => 'https://example.com/orders/'.$order, 'title' => 'Order', 'attributes' => ['order' => $order]]];

        $first = sendMessage('Is it pending?', null, $about('DE-1'))
            ->assertJsonPath('context.direction', 'context')
            ->assertJsonPath('context.body', 'About order DE-1');
        $token = $first->json('visitor_token');
        expect($first->json('context.id'))->toBeLessThan($first->json('message.id'));

        sendMessage('Still there?', $token, $about('DE-1'))->assertJsonPath('context', null);
        sendMessage('And this one?', $token, $about('BR-2'))->assertJsonPath('context.body', 'About order BR-2');

        $directions = collect($this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->json('messages'))
            ->map(fn (array $m) => $m['direction'] === 'context' ? $m['body'] : $m['direction']);
        expect($directions->all())->toBe(['About order DE-1', 'visitor', 'visitor', 'About order BR-2', 'visitor']);
    });

    it('keeps only strings from the page context', function () {
        sendMessage('Hello', null, ['context' => [
            'url' => 'https://example.com/orders/DE-1',
            'attributes' => ['order' => 'DE-1', 'nested' => ['x' => 1], 'count' => 3],
        ]]);

        expect(Conversation::query()->firstOrFail()->context)->toMatchArray([
            'url' => 'https://example.com/orders/DE-1',
            'attributes' => ['order' => 'DE-1', 'count' => '3'],
        ]);
    });
});
