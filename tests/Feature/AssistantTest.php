<?php

use Illuminate\Support\Facades\Queue;
use Threadlive\Assistant\AssistantAnswer;
use Threadlive\Assistant\AssistantService;
use Threadlive\Assistant\DefaultPrompt;
use Threadlive\Console\MakePromptCommand;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\AnnounceHandoff;
use Threadlive\Jobs\AnswerWithAssistant;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Support\ConversationActions;
use Threadlive\Support\Faq;
use Threadlive\Telegram\AssistantPosts;
use Threadlive\Telegram\Dashboard;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TopicName;
use Threadlive\Telegram\Topics;
use Threadlive\Tests\Fixtures\FakeAssistant;

beforeEach(function () {
    FakeAssistant::reset();
    config()->set('threadlive.assistant.enabled', true);
    config()->set('threadlive.assistant.driver', FakeAssistant::class);
    config()->set('threadlive.assistant.name', 'Ava');
    config()->set('threadlive.hours', ['from' => '00:00', 'to' => '00:00', 'tz' => 'UTC']);
});

/** A guest's conversation in topic 300, the AI's; returns it and the token. */
function aiConversation(string $first = 'When are you open?'): array
{
    $token = sendMessage($first)->json('visitor_token');
    $conversation = Conversation::query()->latest('id')->firstOrFail();
    $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

    return [$conversation, $token];
}

function aiAnswers(Conversation $conversation): void
{
    (new AnswerWithAssistant($conversation->id))->handle(app(AssistantService::class), app(AssistantPosts::class));
}

function aiDeliver(Conversation $conversation): void
{
    (new DeliverVisitorMessages($conversation->id))->handle(app(Topics::class), app(TelegramClient::class));
}

describe('who answers a new conversation', function () {
    it('is the assistant, with "typing…" in the widget at once', function () {
        [$conversation, $token] = aiConversation();

        expect($conversation->handler)->toBe(Conversation::AI);

        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])
            ->assertJsonPath('conversation.handler', 'ai')
            ->assertJsonPath('conversation.assistant_typing', true);
    });

    it('is the managers when the assistant is off or has no driver', function (string $key, mixed $value) {
        config()->set("threadlive.assistant.{$key}", $value);
        [$conversation, $token] = aiConversation();

        expect($conversation->handler)->toBe(Conversation::HUMAN);
        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])
            ->assertJsonPath('conversation.assistant_typing', false);
    })->with([
        'off' => ['enabled', false],
        'no such driver' => ['driver', 'App\\Nope'],
    ]);

    it('with first = off_hours, is the managers during the hours and the assistant outside them', function () {
        config()->set('threadlive.assistant.first', 'off_hours');

        config()->set('threadlive.hours', ['from' => '09:00', 'to' => '18:00', 'tz' => 'UTC']);
        $this->travelTo(now()->setTime(12, 0));
        [$day, $token] = aiConversation();
        expect($day->handler)->toBe(Conversation::HUMAN);

        // Reopened in the evening: asked again, the assistant's now.
        $day->forceFill(['status' => ConversationStatus::Closed])->save();
        $this->travelTo(now()->setTime(22, 0));
        sendMessage('Still there?', $token)->assertCreated();
        expect($day->refresh()->handler)->toBe(Conversation::AI);
    });
});

describe('answering', function () {
    it('posts the visitor\'s message silently, then answers it — in the widget and, silently, in the topic', function () {
        fakeTelegram();
        [$conversation, $token] = aiConversation();

        aiDeliver($conversation);
        expect(telegramCalls('sendMessage')[0]['disable_notification'])->toBeTrue();
        Queue::assertPushed(AnswerWithAssistant::class, fn (AnswerWithAssistant $job) => $job->conversationId === $conversation->id);

        aiAnswers($conversation);

        $reply = Message::query()->where('direction', Direction::Agent)->firstOrFail();
        expect($reply->body)->toBe('We are open 9 to 6.')
            ->and($reply->by_assistant)->toBeTrue()
            ->and($reply->agent_name)->toBe('Ava')
            ->and($reply->telegram_message_id)->not->toBeNull();

        $conversation->refresh();
        expect($conversation->assistant_replies)->toBe(1)
            ->and($conversation->status)->toBe(ConversationStatus::Answered)
            ->and($conversation->handler)->toBe(Conversation::AI)
            // The managers' "replied" clock (e-mails of unread replies) is theirs.
            ->and($conversation->last_agent_message_at)->toBeNull();

        $post = collect(telegramCalls('sendMessage'))->last();
        expect($post['text'])->toStartWith('🤖 <b>Ava</b>')
            ->and($post['disable_notification'])->toBeTrue();

        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])
            ->assertJsonPath('messages.1.ai', true)
            ->assertJsonPath('messages.1.author', 'Ava')
            ->assertJsonPath('conversation.assistant_typing', false);
    });

    it('gives the AI the prompt and the conversation as data, never the card lines', function () {
        fakeTelegram();
        config()->set('threadlive.assistant.about', 'We rent bicycles in Lviv.');
        [$conversation] = aiConversation();

        aiAnswers($conversation);

        [$instructions, $text] = FakeAssistant::$calls[0];
        expect($instructions)->toContain('We rent bicycles in Lviv.')
            ->and($instructions)->toContain('"Ava"')
            ->and($instructions)->not->toContain('Orders')  // FakeChatHost's card line
            ->and($text)->toStartWith(AssistantService::CONVERSATION_HEADER)
            ->and($text)->toContain('[visitor] When are you open?');
    });

    it('reads the whole conversation, with a line where the visitor came back after a silence or a close', function () {
        fakeTelegram();
        [$conversation, $token] = aiConversation('Tell us about your company');
        $this->travel(3)->days();
        sendMessage('Where is my order?', $token);
        $conversation->messages()->create(['direction' => Direction::System, 'body' => 'Conversation closed']);
        $this->travel(1)->hours();
        sendMessage('test', $token);
        $this->travel(5)->minutes();
        sendMessage('Hi again', $token);

        $text = app(AssistantService::class)->transcript($conversation->refresh());

        $break = AssistantService::VISIT_BREAK;
        expect($text)->toBe(implode("\n", [
            '[visitor] Tell us about your company',
            $break, // three days of silence
            '[visitor] Where is my order?',
            $break, // closed
            '[visitor] test',
            '[visitor] Hi again',
        ]));

        config()->set('threadlive.assistant.history', 2);
        expect(app(AssistantService::class)->transcript($conversation))->toBe("[visitor] test\n[visitor] Hi again");
    });

    it('answers a message that does not tell its language in the browser\'s', function () {
        [$conversation] = aiConversation('test');
        $conversation->forceFill(['locale' => 'uk_UA', 'visitor_language' => null])->save();

        expect(app(AssistantService::class)->instructions($conversation))
            ->toContain('When it does not tell ("test", "ok",')
            ->toContain('reply in Ukrainian.');
    });

    it('shows the managers its reply in their language under the visitor\'s', function () {
        fakeTelegram();
        FakeAssistant::$next = new AssistantAnswer(false, 'Abrimos de 9 a 6.', 'es', 'We are open 9 to 6.');
        [$conversation] = aiConversation('Hola, ¿cuándo abren?');

        aiAnswers($conversation);

        $post = collect(telegramCalls('sendMessage'))->last()['text'];
        expect($post)->toContain('<blockquote>Abrimos de 9 a 6.</blockquote>')
            ->and($post)->toContain('ES → EN')
            ->and($post)->toContain('We are open 9 to 6.')
            ->and($conversation->refresh()->visitor_language)->toBe('es');
    });

    it('keeps the answer for the visitor when the topic cannot take it', function () {
        fakeTelegram(['sendMessage' => ['error' => 'Bad Request: something']]);
        [$conversation] = aiConversation();

        aiAnswers($conversation);

        expect(Message::query()->where('by_assistant', true)->count())->toBe(1)
            ->and($conversation->refresh()->withAssistant())->toBeTrue();
    });

    it('drops its answer when the visitor wrote again meanwhile: the next job answers both', function () {
        fakeTelegram();
        [$conversation, $token] = aiConversation();
        FakeAssistant::$before = fn () => sendMessage('And on Sundays?', $token);

        aiAnswers($conversation);

        expect(Message::query()->where('direction', Direction::Agent)->count())->toBe(0);
    });

    it('drops its answer when a manager took over meanwhile', function () {
        fakeTelegram();
        [$conversation] = aiConversation();
        FakeAssistant::$before = fn () => telegramUpdate(['message' => managerMessage(300, 'Hi, Maria here.')]);

        aiAnswers($conversation);

        expect(Message::query()->where('by_assistant', true)->count())->toBe(0)
            ->and(Message::query()->where('direction', Direction::Agent)->value('body'))->toBe('Hi, Maria here.');
    });

    it('does nothing when the last word is already an answer', function () {
        fakeTelegram();
        [$conversation] = aiConversation();
        aiAnswers($conversation);

        aiAnswers($conversation);

        expect(FakeAssistant::$calls)->toHaveCount(1);
    });
});

describe('handing off', function () {
    it('on the AI\'s own handoff: its words to the visitor, the managers called with the reason and summary', function () {
        fakeTelegram();
        FakeAssistant::$next = new AssistantAnswer(true, 'A manager will continue here soon.', 'en', '', 'Refund request', 'Wants money back for #12.');
        [$conversation, $token] = aiConversation('I want a refund');

        aiAnswers($conversation);

        $conversation->refresh();
        expect($conversation->handler)->toBe(Conversation::HUMAN)
            ->and($conversation->status)->toBe(ConversationStatus::Waiting)
            ->and($conversation->handoff_reason)->toBe('Refund request')
            ->and($conversation->handed_off_at)->not->toBeNull();

        $notice = Message::query()->where('by_assistant', true)->firstOrFail();
        expect($notice->body)->toBe('A manager will continue here soon.');
        Queue::assertPushed(AnnounceHandoff::class, fn (AnnounceHandoff $job) => $job->noticeId === $notice->id && $job->summary === 'Wants money back for #12.');

        config()->set('threadlive.mention', ['@maria_support', 'oleh_support', 'not a name']);
        (new AnnounceHandoff($conversation->id, 'Wants money back for #12.', $notice->id))->handle(app(AssistantPosts::class), app(AssistantService::class));
        $posts = telegramCalls('sendMessage');
        expect($posts[0]['text'])->toStartWith('🤖 <b>Ava</b>')
            ->and($posts[1]['text'])->toContain('🙋 <b>Needs a manager</b>: Refund request')
            ->and($posts[1]['text'])->toContain('📝 Wants money back for #12.')
            ->and($posts[1]['disable_notification'])->toBeFalse()
            ->and($posts[1]['message_thread_id'])->toBe(300);

        // Nobody has it: General too, with the way to the topic and the managers called.
        $general = $posts[2];
        expect($general['message_thread_id'] ?? null)->toBeNull()
            ->and($general['text'])->toContain("🙋 <b>Guest · #{$conversation->id}</b> needs a manager: Refund request")
            ->and($general['text'])->toContain('@maria_support @oleh_support')
            ->and($general['text'])->not->toContain('not a name')
            ->and($general['disable_notification'])->toBeFalse()
            ->and($general['reply_markup']['inline_keyboard'][0][0]['url'])->toBe('https://t.me/c/1234567890/300');

        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertJsonPath('conversation.handler', 'human');
    });

    it('calls the manager who has the conversation by name in its topic, and leaves General alone', function () {
        fakeTelegram();
        config()->set('threadlive.mention', ['@maria_support']);
        [$conversation] = aiConversation();
        $conversation->forceFill(['assignee_tg_id' => 77, 'assignee_name' => 'Maria', 'handoff_reason' => 'Needs a person'])->save();

        app(AssistantPosts::class)->handoff($conversation, '');

        $posts = telegramCalls('sendMessage');
        expect($posts)->toHaveCount(1)
            ->and($posts[0]['message_thread_id'])->toBe(300)
            ->and($posts[0]['text'])->toContain('<a href="tg://user?id=77">Maria</a>')
            ->and($posts[0]['text'])->not->toContain('@maria_support');
    });

    it('when the AI fails: a plain line for the visitor, the error for the managers', function () {
        fakeTelegram();
        FakeAssistant::$next = new RuntimeException('quota');
        [$conversation] = aiConversation();

        aiAnswers($conversation);

        $conversation->refresh();
        expect($conversation->handler)->toBe(Conversation::HUMAN)
            ->and($conversation->handoff_reason)->toContain('quota')
            ->and(Message::query()->where('direction', Direction::System)->latest('id')->value('body'))
            ->toBe('A manager will continue this conversation here.');
    });

    it('after max_replies answers, and past the daily limit', function (string $key) {
        fakeTelegram();
        config()->set("threadlive.assistant.{$key}", 1);
        [$conversation, $token] = aiConversation();
        aiAnswers($conversation);

        sendMessage('And on Sundays?', $token);
        aiAnswers($conversation->refresh());

        expect(FakeAssistant::$calls)->toHaveCount(1)
            ->and($conversation->refresh()->handler)->toBe(Conversation::HUMAN);
    })->with(['max_replies', 'daily_limit']);

    it('when the visitor asks for a person', function () {
        fakeTelegram();
        [$conversation, $token] = aiConversation();

        $this->postJson('/threadlive/handoff', [], ['X-Threadlive-Token' => $token])
            ->assertOk()
            ->assertJsonPath('conversation.handler', 'human');

        expect($conversation->refresh()->handoff_reason)->toBe('The visitor asked for a person.');
        Queue::assertPushed(AnnounceHandoff::class);

        // Twice: nothing more.
        $this->postJson('/threadlive/handoff', [], ['X-Threadlive-Token' => $token])->assertOk();
        Queue::assertPushed(AnnounceHandoff::class, 1);
    });

    it('when a manager replies in the topic: theirs at once, no "needs a manager" post', function () {
        fakeTelegram();
        [$conversation] = aiConversation();

        telegramUpdate(['message' => managerMessage(300, 'Hi, Maria here.')])->assertOk();

        expect($conversation->refresh()->handler)->toBe(Conversation::HUMAN)
            ->and($conversation->handoff_reason)->toBe('Maria took over.');
        Queue::assertNotPushed(AnnounceHandoff::class);
    });
});

describe('/ai in the topic', function () {
    it('switches the assistant off and on, answering at once when the visitor wrote last', function () {
        fakeTelegram();
        [$conversation] = aiConversation();

        telegramUpdate(['message' => managerMessage(300, '/ai off')]);
        expect($conversation->refresh()->handler)->toBe(Conversation::HUMAN)
            ->and(Message::query()->where('direction', Direction::Agent)->count())->toBe(0);

        telegramUpdate(['message' => managerMessage(300, '/ai', 5001)]);
        expect($conversation->refresh()->handler)->toBe(Conversation::AI)
            ->and(collect(telegramCalls('sendMessage'))->last()['text'])->toContain('The assistant answers this conversation again');
        Queue::assertPushed(AnswerWithAssistant::class);
    });

    it('says so when the assistant is not set up', function () {
        fakeTelegram();
        config()->set('threadlive.assistant.enabled', false);
        [$conversation] = aiConversation();

        telegramUpdate(['message' => managerMessage(300, '/ai on')]);

        expect($conversation->refresh()->handler)->toBe(Conversation::HUMAN)
            ->and(telegramCalls('sendMessage')[0]['text'])->toContain('not set up');
    });

    it('is a button in the action panel', function () {
        fakeTelegram();
        [$conversation] = aiConversation();

        telegramUpdate(['callback_query' => [
            'id' => 'cb1',
            'data' => "tl:ai:{$conversation->id}:off:c",
            'from' => ['id' => 77, 'first_name' => 'Maria'],
            'message' => ['message_id' => 900, 'chat' => ['id' => -1001234567890]],
        ]]);

        expect($conversation->refresh()->handler)->toBe(Conversation::HUMAN);
        $keyboard = json_encode(telegramCalls('editMessageReplyMarkup')[0]['reply_markup'], JSON_UNESCAPED_UNICODE);
        expect($keyboard)->toContain('Managers answer ✓');
    });
});

describe('the managers\' view', function () {
    it('marks the topic 🤖 and keeps the assistant\'s conversations off "waiting"', function () {
        [$conversation] = aiConversation();

        expect(TopicName::for($conversation))->toStartWith('🤖 ')
            ->and(app(Dashboard::class)->html())->toContain('❗ Waiting for a reply (0)')
            ->and(app(Dashboard::class)->html())->toContain('🤖 With the assistant (1)');
    });

    it('sends no waiting alert for it, and counts a handed-off one from the handoff', function () {
        fakeTelegram();
        config()->set('threadlive.hours', ['from' => '00:00', 'to' => '00:00', 'tz' => 'UTC']);
        [$conversation] = aiConversation();
        $this->travel(30)->minutes();

        $this->artisan('threadlive:maintain')->assertSuccessful();
        expect(collect(telegramCalls('sendMessage'))->filter(fn ($c) => str_contains($c['text'], '⏰')))->toHaveCount(0);

        ConversationActions::handOff($conversation, 'Needs a person');
        config()->set('threadlive.mention', ['@maria_support']);
        $this->travel(11)->minutes();
        $this->artisan('threadlive:maintain')->assertSuccessful();
        $alert = collect(telegramCalls('sendMessage'))->first(fn ($c) => str_contains($c['text'], '⏰'));
        expect($alert['text'])->toContain('is waiting 11m — @maria_support');
    });
});

describe('the prompt', function () {
    it('is the package\'s, with who we are, the hours, the FAQ and the page', function () {
        config()->set('threadlive.assistant.about', 'We rent bicycles in Lviv.');
        Faq::save([
            ['id' => 'hours', 'question' => ['en' => 'When are you open?'], 'answer' => ['en' => 'Every day, 9 to 6.'], 'show' => true],
            // Hidden from the widget, still the AI's to know.
            ['id' => 'courier', 'question' => ['en' => 'Which courier?'], 'answer' => ['en' => 'DPD.'], 'show' => false],
        ]);
        [$conversation] = aiConversation();
        $conversation->messages()->create(['direction' => Direction::Context, 'body' => 'Page: Bikes']);

        $service = app(AssistantService::class);
        $instructions = $service->instructions($conversation);

        expect($service->prompt())->toBeInstanceOf(DefaultPrompt::class)
            ->and($instructions)->toContain('We rent bicycles in Lviv.')
            ->and($instructions)->toContain("Q: When are you open?\nA: Every day, 9 to 6.")
            ->and($instructions)->toContain("Q: Which courier?\nA: DPD.")
            ->and($instructions)->toContain('Page: Bikes')
            ->and($instructions)->toContain('around the clock')
            ->and($instructions)->toContain('in English');
    });

    it('is replaced whole by the app\'s class: make-prompt creates it from the package\'s, and it is used', function () {
        cleanInstall();
        $this->artisan('threadlive:make-prompt')->assertSuccessful();

        $source = file_get_contents(app_path('Threadlive/AssistantPrompt.php'));
        expect($source)->toContain('namespace App\\Threadlive;')
            ->and($source)->toContain('final class AssistantPrompt implements Prompt')
            ->and($source)->toContain('use Threadlive\\Assistant\\PromptContext;');
        $this->artisan('threadlive:make-prompt')->assertFailed(); // there already

        // Loaded under a name of this test's own: a class stays loaded for
        // the rest of the run, and AssistantPrompt is the default's.
        $class = 'App\\Threadlive\\OwnTestPrompt';
        config()->set('threadlive.assistant.prompt', $class);
        $this->artisan('threadlive:make-prompt')->assertSuccessful();
        $file = app_path('Threadlive/OwnTestPrompt.php');
        file_put_contents($file, str_replace('You are "{$c->name}"', 'Our own prompt. You are "{$c->name}"', (string) file_get_contents($file)));
        require_once $file;

        [$conversation] = aiConversation();
        expect(app(AssistantService::class)->prompt())->toBeInstanceOf($class)
            ->and(app(AssistantService::class)->instructions($conversation))->toStartWith('Our own prompt. You are "Ava"');

        cleanInstall();
    });

    it('makes the same source as the package\'s, for any class name', function () {
        $source = MakePromptCommand::source('App\\Services\\ChatPrompt');

        expect($source)->toContain('namespace App\\Services;')
            ->and($source)->toContain('final class ChatPrompt implements Prompt')
            ->and($source)->not->toContain('DefaultPrompt');
    });

    it('falls back to the package\'s when the class is not a prompt', function () {
        config()->set('threadlive.assistant.prompt', FakeAssistant::class);

        expect(app(AssistantService::class)->prompt())->toBeInstanceOf(DefaultPrompt::class);
    });

    it('is printed by threadlive:assistant show, and tried by ask', function () {
        $this->artisan('threadlive:assistant show')
            ->expectsOutputToContain(DefaultPrompt::class)
            ->assertSuccessful();

        $this->artisan('threadlive:assistant', ['action' => 'ask', 'question' => 'When are you open?'])
            ->expectsOutputToContain('We are open 9 to 6.')
            ->assertSuccessful();
        expect(FakeAssistant::$calls[0][1])->toContain('[visitor] When are you open?');
    });
});
