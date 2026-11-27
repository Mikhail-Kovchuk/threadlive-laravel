<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Threadlive\ChatUser;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\AnnounceSignIn;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\Topics;
use Threadlive\Tests\Fixtures\FakeChatHost;
use Threadlive\Tests\Fixtures\LookupCommand;

/** A message from a manager in General (no topic). */
function generalMessage(string $text, int $messageId = 6000, ?int $replyTo = null): array
{
    $message = [
        'message_id' => $messageId,
        'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
        'from' => ['id' => 77, 'is_bot' => false, 'first_name' => 'Maria'],
        'text' => $text,
    ];

    if ($replyTo !== null) {
        $message['reply_to_message'] = ['message_id' => $replyTo, 'from' => ['id' => 123, 'is_bot' => true]];
    }

    return $message;
}

function localSetupTelegram(): void
{
    url()->forceRootUrl('http://127.0.0.1:8020');
    fakeTelegram([
        'getMe' => ['id' => 123, 'is_bot' => true, 'username' => 'support_test_bot'],
        'getChat' => ['id' => -1001234567890, 'title' => 'Support', 'type' => 'supergroup', 'is_forum' => true],
        'getChatMember' => ['status' => 'administrator', 'can_manage_topics' => true, 'can_delete_messages' => true, 'can_pin_messages' => true],
    ]);
}

beforeEach(fn () => config()->set('threadlive.commands', [LookupCommand::class]));

describe('manager commands', function () {
    it('answers with what the app\'s command returns, as a reply', function () {
        fakeTelegram();

        telegramUpdate(['message' => generalMessage('/lookup A-1')])->assertOk();

        $answer = telegramCalls('sendMessage')[0];
        expect($answer['text'])->toBe("<b>Record A-1</b>\n• Status: Active\n• Owner: anna@example.com\n"
            .'🛠 <a href="https://admin.example.com/records/1">Open in the admin panel</a>')
            ->and($answer['reply_parameters']['message_id'])->toBe(6000)
            ->and($answer)->not->toHaveKey('message_thread_id');
    });

    it('says when nothing matches', function () {
        fakeTelegram();

        telegramUpdate(['message' => generalMessage('/lookup NOPE')]);

        expect(telegramCalls('sendMessage')[0]['text'])->toBe('Nothing found for “NOPE”.');
    });

    it('asks for the argument when it is missing, and answers the reply', function () {
        fakeTelegram(['sendMessage' => fn (array $params, int $nth) => ['message_id' => 900 + $nth]]);

        telegramUpdate(['message' => generalMessage('/lookup')]);
        $prompt = telegramCalls('sendMessage')[0];
        expect($prompt['reply_markup']['force_reply'])->toBeTrue()
            ->and($prompt['text'])->toContain('Look up a record by its code');

        telegramUpdate(['message' => generalMessage('A-1', 6001, replyTo: 901)]);

        expect(telegramCalls('sendMessage')[1]['text'])->toStartWith('<b>Record A-1</b>');
    });

    it('never passes a command written in a topic on to the visitor', function () {
        fakeTelegram();
        sendMessage('Hello');
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

        telegramUpdate(['message' => managerMessage(300, '/lookup A-1')]);
        telegramUpdate(['message' => managerMessage(300, '/unknown', 5001)]);

        expect($conversation->messages()->where('direction', Direction::Agent)->count())->toBe(0)
            ->and(telegramCalls('sendMessage')[0]['message_thread_id'])->toBe(300)
            ->and(telegramCalls('sendMessage')[1]['text'])->toContain('Unknown command /unknown. Try /settings, /faq, /find, /lookup.');
    });

    it('leaves commands for other bots alone', function () {
        fakeTelegram();

        telegramUpdate(['message' => generalMessage('/start@SomeOtherBot')]);

        expect(telegramCalls('sendMessage'))->toBeEmpty();
    });

    it('puts only /settings in the group\'s "/" menu by default', function () {
        localSetupTelegram();

        $this->artisan('threadlive:setup', ['--local' => true])->assertSuccessful();

        expect(telegramCalls('setMyCommands')[0]['commands'])->toBe([
            ['command' => 'settings', 'description' => 'Settings: the FAQ and more'],
        ]);
    });

    it('puts the commands command_menu lists in the "/" menu — all of them with null', function () {
        localSetupTelegram();
        config()->set('threadlive.command_menu', null);

        $this->artisan('threadlive:setup', ['--local' => true])->assertSuccessful();

        expect(telegramCalls('setMyCommands')[0])->toMatchArray([
            'commands' => [
                ['command' => 'settings', 'description' => 'Settings: the FAQ and more'],
                ['command' => 'menu', 'description' => 'Buttons for this conversation (in its topic)'],
                ['command' => 'close', 'description' => 'Close this conversation (in its topic)'],
                ['command' => 'faq', 'description' => 'Quick answers: get faq.json; send one with the caption /faq to replace it'],
                ['command' => 'find', 'description' => 'Find a conversation: #number, e-mail, name or words from it'],
                ['command' => 'lookup', 'description' => 'Look up a record by its code'],
            ],
            'scope' => ['type' => 'chat', 'chat_id' => '-1001234567890'],
        ]);
    });

    it('picks from the "/" menu what command_menu lists, in its order, naming what is not there', function () {
        localSetupTelegram();
        config()->set('threadlive.command_menu', ['lookup', '/settings', 'translate', 'nope']);

        $this->artisan('threadlive:setup', ['--local' => true])
            ->expectsOutputToContain('there is no command “translate”')
            ->assertSuccessful();

        expect(collect(telegramCalls('setMyCommands')[0]['commands'])->pluck('command')->all())->toBe(['lookup', 'settings']);
    });

    it('keeps the settings in a closed "⚙️ Settings" topic: /settings elsewhere points to it', function () {
        fakeTelegram();
        telegramUpdate(['message' => generalMessage('/settings@support_test_bot')])->assertOk();

        // The topic, made: the panel pinned in it, the topic closed.
        expect(telegramCalls('createForumTopic')[0]['name'])->toBe('⚙️ Settings');
        $panel = telegramCalls('sendMessage')[0];
        expect($panel['message_thread_id'])->toBe(101)
            ->and($panel['reply_markup']['inline_keyboard'][0][0])->toBe(['text' => '📚 FAQ', 'callback_data' => 'tl:set:faq'])
            ->and(telegramCalls('pinChatMessage')[0]['message_id'])->toBe(1001)
            ->and(telegramCalls('closeForumTopic')[0]['message_thread_id'])->toBe(101);

        // In General: the /settings message gone, a button to the topic, quoting nothing.
        $pointer = telegramCalls('sendMessage')[1];
        expect($pointer['message_thread_id'] ?? null)->toBeNull()
            ->and($pointer['reply_parameters'] ?? null)->toBeNull()
            ->and($pointer['reply_markup']['inline_keyboard'][0][0]['url'])->toBe('https://t.me/c/1234567890/101')
            ->and(telegramCalls('deleteMessage')[0]['message_id'])->toBe(6000);

        // /settings in the topic: the panel afresh, the old one removed; no topic made again.
        $inTopic = generalMessage('/settings', 6001);
        $inTopic += ['message_thread_id' => 101, 'is_topic_message' => true];
        telegramUpdate(['message' => $inTopic])->assertOk();
        expect(telegramCalls('createForumTopic'))->toHaveCount(1)
            ->and(collect(telegramCalls('sendMessage'))->last()['message_thread_id'])->toBe(101)
            ->and(array_column(telegramCalls('deleteMessage'), 'message_id'))->toContain(1001);

        // 📚 FAQ from the panel: posted in the topic, quoting nothing.
        telegramUpdate(['callback_query' => [
            'id' => 'cb-set',
            'data' => 'tl:set:faq',
            'from' => ['id' => 77, 'first_name' => 'Maria'],
            'message' => ['message_id' => 1003, 'message_thread_id' => 101, 'is_topic_message' => true, 'chat' => ['id' => -1001234567890]],
        ]])->assertOk();

        $faq = collect(telegramCalls('sendMessage'))->last();
        expect($faq['text'])->toContain('No FAQ yet')
            ->and($faq['message_thread_id'])->toBe(101)
            ->and($faq['reply_parameters'] ?? null)->toBeNull();
    });

    it('makes the settings topic again when it was deleted by hand', function () {
        fakeTelegram();
        telegramUpdate(['message' => generalMessage('/settings')]);

        fakeTelegram(['reopenForumTopic' => fn (array $params, int $nth) => $nth === 2 ? ['error' => 'Bad Request: TOPIC_DELETED'] : true]);
        telegramUpdate(['message' => generalMessage('/settings', 6001)]);

        expect(telegramCalls('createForumTopic'))->toHaveCount(2)
            ->and(collect(telegramCalls('sendMessage'))->last()['reply_markup']['inline_keyboard'][0][0]['url'])->toBe('https://t.me/c/1234567890/102');
    });

    it('answers an unknown command addressed to this bot by name, and stays out of other bots\'', function () {
        fakeTelegram(['getMe' => ['id' => 999, 'is_bot' => true, 'username' => 'support_test_bot']]);

        telegramUpdate(['message' => generalMessage('/nope@Support_Test_Bot')]);
        expect(telegramCalls('sendMessage')[0]['text'])->toContain('Unknown command /nope');

        telegramUpdate(['message' => generalMessage('/start@SomeOtherBot', 6001)]);
        expect(telegramCalls('sendMessage'))->toHaveCount(1)
            ->and(telegramCalls('getMe'))->toHaveCount(1); // asked once
    });

    it('names a wrong entry in the commands list on setup', function () {
        localSetupTelegram();
        config()->set('threadlive.commands', ['App\\Threadlive\\Commands\\MissingCommand']);

        $this->artisan('threadlive:setup', ['--local' => true])
            ->expectsOutputToContain('MissingCommand')
            ->assertFailed();

        expect(telegramCalls('setMyCommands'))->toBeEmpty();
    });
});

describe('threadlive:make-command', function () {
    beforeEach(fn () => cleanInstall());
    afterEach(fn () => cleanInstall());

    it('creates the class and lists it in the published config', function () {
        $this->artisan('threadlive:install', ['--no-migrate' => true])->assertSuccessful();

        $this->artisan('threadlive:make-command', ['name' => 'Invoice'])->assertSuccessful();
        $this->artisan('threadlive:make-command', ['name' => 'customer-account'])->assertSuccessful();

        $class = file_get_contents(app_path('Threadlive/Commands/InvoiceCommand.php'));
        expect($class)->toContain('class InvoiceCommand implements ManagerCommand')
            ->and($class)->toContain("return 'invoice';")
            ->and(app_path('Threadlive/Commands/CustomerAccountCommand.php'))->toBeFile()
            ->and(file_get_contents(config_path('threadlive.php')))->toContain(
                "'commands' => [\n        App\\Threadlive\\Commands\\CustomerAccountCommand::class,\n        App\\Threadlive\\Commands\\InvoiceCommand::class,\n    ],"
            );
    });

    it('adds the commands key to a config published before there was one', function () {
        file_put_contents(config_path('threadlive.php'), "<?php\n\nreturn [\n\n    'enabled' => false,\n\n];\n");

        $this->artisan('threadlive:make-command', ['name' => 'Invoice'])->assertSuccessful();

        $config = require config_path('threadlive.php');
        expect($config['commands'])->toBe(['App\\Threadlive\\Commands\\InvoiceCommand'])
            ->and($config['enabled'])->toBeFalse();
    });

    it('refuses a name Telegram would not take, or one the package uses', function () {
        $this->artisan('threadlive:make-command', ['name' => 'Close'])->assertFailed();
        $this->artisan('threadlive:make-command', ['name' => 'find'])->assertFailed();
    });
});

describe('/find', function () {
    /** A conversation in topic 300 + n, with messages. */
    function found(string $name, ?string $email, array $bodies, array $attributes = []): Conversation
    {
        $conversation = Conversation::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'telegram_thread_id' => 300 + Conversation::query()->count(),
            'topic_state' => TopicState::Open,
            'last_activity_at' => now(),
            ...$attributes,
        ]);
        foreach ($bodies as $body) {
            $conversation->messages()->create(['direction' => Direction::Visitor, 'body' => $body]);
        }

        return $conversation;
    }

    beforeEach(function () {
        fakeTelegram();
        config()->set('threadlive.chat_id', '-1001234567890');
    });

    it('finds by words in the messages, newest first, linked to the topics', function () {
        $old = found('Anna', 'anna@example.com', ['I would like a refund for the second one, please.'], ['last_activity_at' => now()->subDays(3)]);
        $new = found('John', null, ['Where is my REFUND?'], ['status' => ConversationStatus::Closed]);
        found('Maria', null, ['Thanks!']);

        telegramUpdate(['message' => generalMessage('/find refund')]);

        $text = telegramCalls('sendMessage')[0]['text'];
        expect($text)->toStartWith('<b>🔎 2 conversations for “refund”</b>')
            ->and($text)->toContain("• John #{$new->id} · ✅ ")
            ->and($text)->toContain('<a href="https://t.me/c/1234567890/'.$new->telegram_thread_id.'">“Where is my REFUND?”</a>')
            ->and($text)->toContain("• Anna #{$old->id} · ❗ ")
            ->and($text)->toContain('“I would like a refund for the second one, please.”')
            ->and(strpos($text, 'John'))->toBeLessThan(strpos($text, 'Anna'))
            ->and($text)->not->toContain('Maria');
    });

    it('finds by number and by e-mail', function () {
        $anna = found('Anna', 'anna@example.com', ['Hi']);
        found('John', 'john@example.com', ['Hello']);

        telegramUpdate(['message' => generalMessage("/find #{$anna->id}")]);
        telegramUpdate(['message' => generalMessage('/find anna@example', 6001)]);

        expect(telegramCalls('sendMessage')[0]['text'])->toStartWith("<b>🔎 Conversation #{$anna->id}</b>\n• Anna #{$anna->id}")
            ->and(telegramCalls('sendMessage')[0]['text'])->toContain('>open the topic</a>')
            ->and(telegramCalls('sendMessage')[1]['text'])->toStartWith('<b>🔎 1 conversation for “anna@example”</b>')
            ->and(telegramCalls('sendMessage')[1]['text'])->not->toContain('John');
    });

    it('takes % and _ literally, and says when nothing matches', function () {
        found('Anna', null, ['all good']);

        telegramUpdate(['message' => generalMessage('/find %')]);
        telegramUpdate(['message' => generalMessage('/find a_l', 6001)]);

        expect(telegramCalls('sendMessage')[0]['text'])->toBe('Nothing found for “%”.')
            ->and(telegramCalls('sendMessage')[1]['text'])->toBe('Nothing found for “a_l”.');
    });

    it('works with no app commands at all', function () {
        config()->set('threadlive.commands', []);
        found('Anna', null, ['Hi there']);

        telegramUpdate(['message' => generalMessage('/find there')]);

        expect(telegramCalls('sendMessage')[0]['text'])->toStartWith('<b>🔎 1 conversation');
    });
});

describe('a guest signing in mid-conversation', function () {
    it('announces the user in the topic, with the admin link, and renames it', function () {
        fakeTelegram();
        $token = sendMessage('Hi, as a guest')->json('visitor_token');
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open, 'topic_name' => "❗ Guest · #{$conversation->id}"])->save();

        FakeChatHost::$user = new ChatUser('42', 'Anna', 'anna@example.com');
        FakeChatHost::$adminUrl = 'https://admin.example.com/users/42';
        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertOk();

        Queue::assertPushed(AnnounceSignIn::class);
        (new AnnounceSignIn($conversation->id))->handle(app(Topics::class), app(TelegramClient::class));

        expect(telegramCalls('sendMessage')[0]['text'])->toBe('👤 Signed in: <b>Anna</b> · anna@example.com · '
            .'<a href="https://admin.example.com/users/42">Open in the admin panel</a>')
            ->and(telegramCalls('sendMessage')[0]['message_thread_id'])->toBe(300)
            ->and(telegramCalls('editForumTopic')[0]['name'])->toBe("❗ Anna · #{$conversation->id}");
    });

    it('stays quiet while the conversation has no topic yet: the card will show the user', function () {
        fakeTelegram();
        $token = sendMessage('Hi')->json('visitor_token');
        FakeChatHost::$user = new ChatUser('42', 'Anna');
        $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token]);

        (new AnnounceSignIn(Conversation::query()->firstOrFail()->id))->handle(app(Topics::class), app(TelegramClient::class));

        expect(telegramCalls('sendMessage'))->toBeEmpty();
    });
});
