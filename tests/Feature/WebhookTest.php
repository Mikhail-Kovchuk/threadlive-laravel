<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Events\AgentReplied;
use Threadlive\Events\ConversationClosed;
use Threadlive\Jobs\SyncTopic;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\Topics;

/**
 * A guest conversation with an open topic, thread 300, and the guest's token.
 *
 * @return array{Conversation, string}
 */
function conversationInTopic(): array
{
    $token = sendMessage('Hi, I need help')->json('visitor_token');
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

    return [$conversation, $token];
}

it('refuses an update without the secret Telegram was given', function () {
    telegramUpdate(['message' => managerMessage(300, 'Hi')], 'wrong')->assertForbidden();
    test()->postJson('/threadlive/telegram/webhook', ['message' => managerMessage(300, 'Hi')])->assertForbidden();
});

it('passes a manager reply to the visitor and assigns the first replier', function () {
    Event::fake([AgentReplied::class]);
    [$conversation, $token] = conversationInTopic();

    telegramUpdate(['message' => managerMessage(300, 'Hi! Let me check.')])->assertOk();

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Answered)
        ->and($conversation->assignee_name)->toBe('Maria')
        ->and($conversation->last_agent_message_at)->not->toBeNull();

    $seen = collect($this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->json('messages'))
        ->map(fn (array $m) => [$m['direction'], $m['body'], $m['author']]);
    expect($seen->all())->toBe([
        ['visitor', 'Hi, I need help', null],
        ['system', 'Maria joined the chat', null],
        ['agent', 'Hi! Let me check.', 'Maria'],
    ]);

    Queue::assertPushed(SyncTopic::class);
    Event::assertDispatched(AgentReplied::class);
});

it('keeps a // message as an internal note the visitor never sees', function () {
    [$conversation, $token] = conversationInTopic();

    telegramUpdate(['message' => managerMessage(300, '// refund approved, tell her tomorrow')])->assertOk();

    expect($conversation->messages()->where('direction', Direction::Note)->value('body'))->toBe('// refund approved, tell her tomorrow')
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Waiting);
    $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertJsonCount(1, 'messages');
});

it('closes the conversation on /close, telling the visitor', function () {
    Event::fake([ConversationClosed::class]);
    [$conversation, $token] = conversationInTopic();

    telegramUpdate(['message' => managerMessage(300, '/close')])->assertOk();

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Closed)
        ->and($conversation->closed_by)->toBe('agent')
        ->and($conversation->messages()->where('direction', Direction::System)->value('body'))->toBe('Conversation closed');
    Event::assertDispatched(ConversationClosed::class);
});

it('shows the visitor an edited reply', function () {
    [$conversation, $token] = conversationInTopic();
    telegramUpdate(['message' => managerMessage(300, 'Wrong text', 5001)]);

    telegramUpdate(['edited_message' => managerMessage(300, 'Right text', 5001)])->assertOk();

    $reply = $conversation->messages()->where('direction', Direction::Agent)->firstOrFail();
    expect($reply->body)->toBe('Right text')->and($reply->edited_at)->not->toBeNull();
});

it('ignores bots, other chats, the General topic and unknown topics', function () {
    [$conversation, $token] = conversationInTopic();
    $before = $conversation->messages()->count();

    $bot = managerMessage(300, 'From a bot');
    $bot['from']['is_bot'] = true;
    $otherChat = managerMessage(300, 'Elsewhere');
    $otherChat['chat']['id'] = -100999;
    $general = managerMessage(300, 'In General');
    unset($general['message_thread_id'], $general['is_topic_message']);

    foreach ([$bot, $otherChat, $general, managerMessage(999, 'Unknown topic')] as $message) {
        telegramUpdate(['message' => $message])->assertOk();
    }

    expect($conversation->messages()->count())->toBe($before);
});

it('deletes the "renamed the topic" lines of its own renames, and only those', function () {
    fakeTelegram();
    conversationInTopic();

    $rename = fn (int $id, array $from) => ['message' => [
        'message_id' => $id,
        'message_thread_id' => 300,
        'is_topic_message' => true,
        'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
        'from' => $from,
        'forum_topic_edited' => ['name' => '💬 Maria → Guest · #1'],
    ]];

    // The test token is "123:test-token": the bot's id is 123.
    telegramUpdate($rename(700, ['id' => 123, 'is_bot' => true, 'first_name' => 'threadlive']))->assertOk();
    telegramUpdate($rename(701, ['id' => 77, 'is_bot' => false, 'first_name' => 'Maria']))->assertOk();

    expect(telegramCalls('deleteMessage'))->toHaveCount(1)
        ->and(telegramCalls('deleteMessage')[0])->toMatchArray(['chat_id' => '-1001234567890', 'message_id' => 700]);
});

it('takes a conversation with the Take button', function () {
    fakeTelegram();
    [$conversation, $token] = conversationInTopic();

    telegramUpdate(['callback_query' => [
        'id' => 'cb1',
        'data' => "tl:claim:{$conversation->id}",
        'from' => ['id' => 88, 'is_bot' => false, 'first_name' => 'Olesya'],
        'message' => ['message_id' => 10, 'chat' => ['id' => -1001234567890]],
    ]])->assertOk();

    expect($conversation->refresh()->assignee_name)->toBe('Olesya')
        ->and(telegramCalls('answerCallbackQuery')[0]['text'])->toBe('It’s yours.');
});

it('answers that taking is off, and drops the button, when the setting was turned off', function () {
    fakeTelegram();
    config()->set('threadlive.claim_button', false);
    [$conversation, $token] = conversationInTopic();

    telegramUpdate(['callback_query' => [
        'id' => 'cb2',
        'data' => "tl:claim:{$conversation->id}",
        'from' => ['id' => 88, 'first_name' => 'Olesya'],
        'message' => ['message_id' => 10, 'chat' => ['id' => -1001234567890]],
    ]])->assertOk();

    expect($conversation->refresh()->assignee_name)->toBeNull()
        ->and(telegramCalls('answerCallbackQuery')[0]['text'])->toBe('Taking conversations is turned off.')
        ->and(telegramCalls('editMessageReplyMarkup')[0]['reply_markup']['inline_keyboard'][0])->toHaveCount(1);
});

it('brings the topic name and state in line: 💬 after a reply, closed after /close', function () {
    fakeTelegram();
    [$conversation, $token] = conversationInTopic();
    $topics = app(Topics::class);

    telegramUpdate(['message' => managerMessage(300, 'On it')]);
    $topics->sync($conversation->refresh());
    expect(collect(telegramCalls('editForumTopic'))->last()['name'])->toBe("💬 Maria → Guest · #{$conversation->id}");

    telegramUpdate(['message' => managerMessage(300, '/close', 5002)]);
    $topics->sync($conversation->refresh());

    expect(collect(telegramCalls('editForumTopic'))->last()['name'])->toBe("✅ Maria → Guest · #{$conversation->id}")
        ->and(telegramCalls('closeForumTopic'))->toHaveCount(1)
        ->and($conversation->refresh()->topic_state)->toBe(TopicState::Closed);

    $topics->sync($conversation); // nothing changed: no more calls
    expect(telegramCalls('editForumTopic'))->toHaveCount(2);
});
