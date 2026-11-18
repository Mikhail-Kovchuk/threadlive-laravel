<?php

use Illuminate\Support\Facades\Queue;
use Threadlive\ChatUser;
use Threadlive\Enums\Delivery;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\Topics;
use Threadlive\Tests\Fixtures\FakeChatHost;

function deliver(?Conversation $conversation = null): void
{
    $conversation ??= Conversation::query()->firstOrFail();
    (new DeliverVisitorMessages($conversation->id))->handle(app(Topics::class), app(TelegramClient::class));
}

it('queues delivery for each visitor message', function () {
    sendMessage('Hello');

    Queue::assertPushed(DeliverVisitorMessages::class, fn (DeliverVisitorMessages $job) => $job->conversationId === Conversation::query()->firstOrFail()->id);
});

it('opens a topic named for its state, posts the visitor card, then the message', function () {
    fakeTelegram();
    FakeChatHost::$user = new ChatUser('42', 'Anna', 'anna@example.com');
    sendMessage('Is my withdrawal still pending?', null, ['context' => ['url' => 'https://example.com/orders/DE-1', 'title' => 'Order DE-1']]);

    deliver();

    $conversation = Conversation::query()->firstOrFail();
    expect(telegramCalls('createForumTopic'))->toHaveCount(1)
        ->and(telegramCalls('createForumTopic')[0]['name'])->toBe("❗ Anna · #{$conversation->id}")
        ->and($conversation->telegram_thread_id)->toBe(101)
        ->and($conversation->topic_state)->toBe(TopicState::Open);

    [$card, $message] = telegramCalls('sendMessage');
    expect($card['message_thread_id'])->toBe(101)
        ->and($card['text'])->toContain('<b>Anna</b> · anna@example.com · signed in')
        ->and($card['text'])->toContain('<a href="https://example.com/orders/DE-1">Order DE-1</a>')
        ->and($card['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe("tl:claim:{$conversation->id}")
        ->and($card['disable_notification'])->toBeTrue()
        ->and($message['text'])->toBe('Is my withdrawal still pending?')
        ->and($message['disable_notification'])->toBeFalse();

    expect($conversation->messages()->first()?->delivery)->toBe(Delivery::Sent);
});

it('sends a burst as one message, escaped, in the same topic as before', function () {
    fakeTelegram();
    $token = sendMessage('First')->json('visitor_token');
    deliver();

    sendMessage('<b>bold</b> & more', $token);
    sendMessage('Third', $token);
    deliver();

    expect(telegramCalls('createForumTopic'))->toHaveCount(1)
        ->and(collect(telegramCalls('sendMessage'))->last()['text'])->toBe("&lt;b&gt;bold&lt;/b&gt; &amp; more\n\nThird");
});

it('posts a context card as its own silent message before the visitor message', function () {
    fakeTelegram();
    FakeChatHost::$contextLine = fn () => 'About order DE-1';
    sendMessage('Hello');

    deliver();

    $sends = telegramCalls('sendMessage');
    expect($sends)->toHaveCount(3) // card, context, message
        ->and($sends[1]['text'])->toBe('📋 <i>About order DE-1</i>')
        ->and($sends[1]['disable_notification'])->toBeTrue()
        ->and($sends[2]['text'])->toBe('Hello');
});

it('creates a new topic with a summary when the old one was deleted by hand', function () {
    fakeTelegram([
        'sendMessage' => fn (array $params, int $nth) => $nth === 3 ? ['error' => 'Bad Request: message thread not found'] : null,
    ]);
    $token = sendMessage('Before')->json('visitor_token');
    deliver(); // card + "Before"

    sendMessage('After the topic was deleted', $token);
    deliver(); // fails on the deleted topic, then: new topic, card, summary, message

    $conversation = Conversation::query()->firstOrFail();
    $sends = telegramCalls('sendMessage');

    expect(telegramCalls('createForumTopic'))->toHaveCount(2)
        ->and($conversation->telegram_thread_id)->toBe(102)
        ->and(collect($sends)->pluck('text')->first(fn (string $t) => str_contains($t, 'Returning visitor')))->toContain('👤 Visitor: Before')
        ->and(collect($sends)->last()['text'])->toBe('After the topic was deleted')
        ->and(collect($sends)->last()['message_thread_id'])->toBe(102)
        ->and($conversation->messages()->where('delivery', Delivery::Pending)->count())->toBe(0);
});

it('reopens a closed topic when the visitor writes again', function () {
    fakeTelegram();
    $token = sendMessage('Hello')->json('visitor_token');
    deliver();
    Conversation::query()->firstOrFail()->forceFill(['status' => 'closed', 'topic_state' => TopicState::Closed])->save();

    sendMessage('Me again', $token);
    deliver();

    expect(telegramCalls('reopenForumTopic'))->toHaveCount(1)
        ->and(Conversation::query()->firstOrFail()->topic_state)->toBe(TopicState::Open);
});

it('marks what it could not send as failed when it gives up', function () {
    sendMessage('Hello');
    $conversation = Conversation::query()->firstOrFail();

    (new DeliverVisitorMessages($conversation->id))->failed(new RuntimeException('down'));

    expect($conversation->messages()->where('direction', Direction::Visitor)->first()?->delivery)->toBe(Delivery::Failed);
});
