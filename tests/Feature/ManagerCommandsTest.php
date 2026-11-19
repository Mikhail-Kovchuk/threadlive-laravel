<?php

use Illuminate\Support\Facades\Queue;
use Threadlive\ChatUser;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\AnnounceSignIn;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\Topics;
use Threadlive\Tests\Fixtures\FakeChatHost;

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

beforeEach(function () {
    FakeChatHost::$commands = ['order' => 'Look up an order by its code'];
    FakeChatHost::$runCommand = fn (string $command, string $code) => $code === 'DE-1'
        ? [
            'title' => 'Order DE-1',
            'lines' => [['label' => 'Status', 'value' => 'Paid'], ['label' => 'Client', 'value' => 'anna@example.com']],
            'url' => 'https://admin.example.com/orders/DE-1',
        ]
        : null;
});

describe('manager commands', function () {
    it('answers /order with the host\'s summary and the admin link, as a reply', function () {
        fakeTelegram();

        telegramUpdate(['message' => generalMessage('/order DE-1')])->assertOk();

        $answer = telegramCalls('sendMessage')[0];
        expect($answer['text'])->toBe("<b>Order DE-1</b>\n• Status: Paid\n• Client: anna@example.com\n"
            .'🛠 <a href="https://admin.example.com/orders/DE-1">Open in the admin panel</a>')
            ->and($answer['reply_parameters']['message_id'])->toBe(6000)
            ->and($answer)->not->toHaveKey('message_thread_id');
    });

    it('says when nothing matches', function () {
        fakeTelegram();

        telegramUpdate(['message' => generalMessage('/order NOPE')]);

        expect(telegramCalls('sendMessage')[0]['text'])->toBe('Nothing found for “NOPE”.');
    });

    it('asks for the code when it is missing, and answers the reply', function () {
        fakeTelegram(['sendMessage' => fn (array $params, int $nth) => ['message_id' => 900 + $nth]]);

        telegramUpdate(['message' => generalMessage('/order')]);
        $prompt = telegramCalls('sendMessage')[0];
        expect($prompt['reply_markup']['force_reply'])->toBeTrue()
            ->and($prompt['text'])->toContain('Look up an order by its code');

        telegramUpdate(['message' => generalMessage('DE-1', 6001, replyTo: 901)]);

        expect(telegramCalls('sendMessage')[1]['text'])->toStartWith('<b>Order DE-1</b>');
    });

    it('never passes a command written in a topic on to the visitor', function () {
        fakeTelegram();
        sendMessage('Hello');
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

        telegramUpdate(['message' => managerMessage(300, '/order DE-1')]);
        telegramUpdate(['message' => managerMessage(300, '/unknown', 5001)]);

        expect($conversation->messages()->where('direction', Direction::Agent)->count())->toBe(0)
            ->and(telegramCalls('sendMessage')[0]['message_thread_id'])->toBe(300)
            ->and(telegramCalls('sendMessage')[1]['text'])->toContain('Unknown command /unknown. Try /order.');
    });

    it('leaves /close to the topic, and commands for other bots alone', function () {
        fakeTelegram();

        telegramUpdate(['message' => generalMessage('/start@SomeOtherBot')]);

        expect(telegramCalls('sendMessage'))->toBeEmpty();
    });

    it('lists the commands in the group\'s menu on setup', function () {
        url()->forceRootUrl('http://127.0.0.1:8020');
        fakeTelegram([
            'getMe' => ['id' => 123, 'is_bot' => true, 'username' => 'support_test_bot'],
            'getChat' => ['id' => -1001234567890, 'title' => 'Support', 'type' => 'supergroup', 'is_forum' => true],
            'getChatMember' => ['status' => 'administrator', 'can_manage_topics' => true, 'can_delete_messages' => true, 'can_pin_messages' => true],
        ]);

        $this->artisan('threadlive:setup', ['--local' => true])->assertSuccessful();

        expect(telegramCalls('setMyCommands')[0])->toMatchArray([
            'commands' => [
                ['command' => 'close', 'description' => 'Close this conversation (in its topic)'],
                ['command' => 'order', 'description' => 'Look up an order by its code'],
            ],
            'scope' => ['type' => 'chat', 'chat_id' => '-1001234567890'],
        ]);
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
