<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;

it('takes a manager reply through getUpdates, as the webhook would, and remembers where it got to', function () {
    sendMessage('Hello');
    $conversation = Conversation::query()->firstOrFail();
    $conversation->forceFill(['telegram_thread_id' => 300, 'topic_state' => TopicState::Open])->save();

    fakeTelegram([
        'getWebhookInfo' => ['url' => ''],
        'getUpdates' => fn (array $params, int $nth) => $nth === 1
            ? [['update_id' => 41, 'message' => managerMessage(300, 'Answered without a webhook')]]
            : [],
    ]);

    $this->artisan('threadlive:listen', ['--once' => true])
        ->expectsOutputToContain('message from Maria in topic 300')
        ->assertSuccessful();
    $this->artisan('threadlive:listen', ['--once' => true])->assertSuccessful();

    expect($conversation->messages()->where('direction', Direction::Agent)->value('body'))->toBe('Answered without a webhook')
        ->and(telegramCalls('getUpdates')[1]['offset'])->toBe(42)
        ->and(Cache::get('threadlive:listen:offset'))->toBe(42);
});

it('turns a dropped connection into a TelegramException without the bot token', function () {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Connection timed out for https://api.telegram.org/bot123:test-token/getUpdates'
    ));

    $error = null;
    try {
        app(TelegramClient::class)->call('getUpdates');
    } catch (TelegramException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull()
        ->and($error?->getMessage())->toContain('connection failed')
        ->and($error?->getMessage())->not->toContain('test-token')
        ->and($error?->getMessage())->toContain('bot***/getUpdates');
});

it('will not take updates away from a webhook unless told to', function () {
    fakeTelegram(['getWebhookInfo' => ['url' => 'https://support.example.com/threadlive/telegram/webhook']]);

    $this->artisan('threadlive:listen', ['--once' => true])
        ->expectsOutputToContain('Never on the production bot')
        ->assertFailed();

    expect(telegramCalls('deleteWebhook'))->toBeEmpty()
        ->and(telegramCalls('getUpdates'))->toBeEmpty();
});

it('removes the webhook with --drop-webhook', function () {
    fakeTelegram([
        'getWebhookInfo' => ['url' => 'https://dev.example.com/threadlive/telegram/webhook'],
        'getUpdates' => [],
    ]);

    $this->artisan('threadlive:listen', ['--once' => true, '--drop-webhook' => true])->assertSuccessful();

    expect(telegramCalls('deleteWebhook'))->toHaveCount(1);
});

it('sets up for local work: checks everything, sets no webhook, needs no HTTPS', function () {
    url()->forceRootUrl('http://127.0.0.1:8020');
    fakeTelegram([
        'getMe' => ['id' => 999, 'is_bot' => true, 'username' => 'support_test_bot'],
        'getChat' => ['id' => -1001234567890, 'title' => 'Support', 'type' => 'supergroup', 'is_forum' => true],
        'getChatMember' => ['status' => 'administrator', 'can_manage_topics' => true, 'can_delete_messages' => true, 'can_pin_messages' => true],
    ]);

    $this->artisan('threadlive:setup', ['--local' => true])
        ->expectsOutputToContain('threadlive:listen')
        ->assertSuccessful();

    expect(telegramCalls('setWebhook'))->toBeEmpty()
        ->and(telegramCalls('pinChatMessage'))->toHaveCount(1);
});
