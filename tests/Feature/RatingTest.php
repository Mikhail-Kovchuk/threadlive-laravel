<?php

use Illuminate\Support\Facades\Queue;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Jobs\AnnounceRating;
use Threadlive\Models\Conversation;
use Threadlive\Support\ConversationActions;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Translation\TranslationService;

/** A guest's conversation in its topic (300, 301, …) that a manager answered, then closed; returns it and the token. */
function closedConversation(bool $answered = true): array
{
    $token = sendMessage('My invoice is wrong.')->json('visitor_token');
    $conversation = Conversation::query()->latest('id')->firstOrFail();
    $conversation->forceFill(['telegram_thread_id' => 299 + $conversation->id, 'topic_state' => TopicState::Closed])->save();

    if ($answered) {
        $conversation->messages()->create(['direction' => Direction::Agent, 'body' => 'Fixed.', 'agent_name' => 'Maria']);
    }
    ConversationActions::close($conversation, 'agent');

    return [$conversation->refresh(), $token];
}

function rate(string $token, array $data)
{
    return test()->postJson('/threadlive/rating', $data, ['X-Threadlive-Token' => $token]);
}

function announceRating(Conversation $conversation): void
{
    (new AnnounceRating($conversation->id))->handle(app(TelegramClient::class), app(TranslationService::class));
}

it('asks for a rating once a conversation someone answered is closed', function () {
    Queue::fake();
    [, $token] = closedConversation();

    $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])
        ->assertJsonPath('conversation.rating', ['value' => null, 'comment' => null]);
});

it('asks nothing while it is open, when nobody answered, or with rating off', function () {
    Queue::fake();
    $token = sendMessage('Hello')->json('visitor_token');
    $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertJsonPath('conversation.rating', null);
    rate($token, ['rating' => 'good'])->assertStatus(422)->assertJsonPath('message', 'There is nothing to rate yet.');

    [, $token] = closedConversation(answered: false);
    $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertJsonPath('conversation.rating', null);

    config()->set('threadlive.rating', false);
    [, $token] = closedConversation();
    $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertJsonPath('conversation.rating', null);
});

it('takes the thumb, then a comment, telling the topic each time; a repeat changes nothing', function () {
    Queue::fake();
    [$conversation, $token] = closedConversation();

    rate($token, ['rating' => 'bad'])->assertOk()->assertJsonPath('conversation.rating', ['value' => 'bad', 'comment' => null]);
    rate($token, ['rating' => 'bad', 'comment' => '  Too slow.  '])->assertOk()
        ->assertJsonPath('conversation.rating', ['value' => 'bad', 'comment' => 'Too slow.']);
    // The thumb changed: the comment stays.
    rate($token, ['rating' => 'good'])->assertJsonPath('conversation.rating', ['value' => 'good', 'comment' => 'Too slow.']);
    rate($token, ['rating' => 'good']);

    Queue::assertPushed(AnnounceRating::class, 3);
    expect($conversation->refresh()->rating)->toBe(Conversation::GOOD)
        ->and($conversation->rated_at)->not->toBeNull();
});

it('refuses what is not a thumb, or a comment too long', function () {
    Queue::fake();
    [, $token] = closedConversation();

    rate($token, ['rating' => 'meh'])->assertStatus(422)->assertJsonValidationErrors('rating');
    rate($token, ['rating' => 'bad', 'comment' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonValidationErrors('comment');
});

it('asks again after the next close: writing again reopens it', function () {
    Queue::fake();
    [$conversation, $token] = closedConversation();
    rate($token, ['rating' => 'good']);

    sendMessage('One more thing', $token);
    $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])->assertJsonPath('conversation.rating', null);

    $this->travel(1)->minutes();
    ConversationActions::close($conversation->refresh(), 'agent');
    $this->getJson('/threadlive/messages', ['X-Threadlive-Token' => $token])
        ->assertJsonPath('conversation.rating', ['value' => null, 'comment' => null]);
});

it('says 404 to a visitor without a conversation', function () {
    $this->postJson('/threadlive/rating', ['rating' => 'good'], ['X-Threadlive-Token' => str_repeat('x', 48)])->assertNotFound();
});

describe('in the topic', function () {
    it('posts a 👍 silently, a 👎 with sound, and edits the same post with the comment', function () {
        fakeTelegram();
        Queue::fake();
        [$conversation] = closedConversation();

        $conversation->forceFill(['rating' => 'good', 'rated_at' => now()])->save();
        announceRating($conversation);
        $post = collect(telegramCalls('sendMessage'))->last();
        expect($post['message_thread_id'])->toBe(300)
            ->and($post['text'])->toContain('👍 <b>The visitor rated the conversation: good</b>')
            ->and($post['disable_notification'])->toBeTrue();

        $conversation->forceFill(['rating' => 'bad', 'rating_comment' => 'Too <slow>.'])->save();
        announceRating($conversation);
        $edit = collect(telegramCalls('editMessageText'))->last();
        expect($edit['message_id'])->toBe(1001) // the fake's first message id
            ->and($edit['text'])->toContain('👎')
            ->and($edit['text'])->toContain('Too &lt;slow&gt;.');
    });

    it('posts afresh after the next close, with sound for a 👎', function () {
        fakeTelegram();
        Queue::fake();
        [$conversation] = closedConversation();
        $conversation->forceFill(['rating' => 'good', 'rated_at' => now()])->save();
        announceRating($conversation);

        $conversation->forceFill(['closed_at' => now()->addMinute(), 'rating' => 'bad', 'rated_at' => now()->addMinutes(2)])->save();
        announceRating($conversation);

        expect(telegramCalls('editMessageText'))->toBeEmpty()
            ->and(collect(telegramCalls('sendMessage'))->last()['disable_notification'])->toBeFalse();
    });

    it('reopens a topic it may not write in for the post, and closes it again', function () {
        fakeTelegram(['sendMessage' => fn (array $params, int $n) => $n === 1 ? ['error' => 'Bad Request: TOPIC_CLOSED'] : ['message_id' => 1500]]);
        Queue::fake();
        [$conversation] = closedConversation();
        $conversation->forceFill(['rating' => 'good', 'rated_at' => now()])->save();

        announceRating($conversation);

        expect(telegramCalls('reopenForumTopic'))->toHaveCount(1)
            ->and(telegramCalls('closeForumTopic'))->toHaveCount(1)
            ->and(telegramCalls('sendMessage'))->toHaveCount(2);
    });

    it('posts nothing for a deleted topic', function () {
        fakeTelegram();
        Queue::fake();
        [$conversation] = closedConversation();
        $conversation->forceFill(['rating' => 'good', 'rated_at' => now(), 'topic_state' => TopicState::Deleted])->save();

        announceRating($conversation);

        expect(telegramCalls('sendMessage'))->toBeEmpty();
    });
});
