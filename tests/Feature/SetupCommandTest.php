<?php

function healthyGroup(array $overrides = []): void
{
    fakeTelegram([
        'getMe' => ['id' => 999, 'is_bot' => true, 'username' => 'support_test_bot'],
        'getChat' => ['id' => -1001234567890, 'title' => 'Support', 'type' => 'supergroup', 'is_forum' => true],
        'getChatMember' => [
            'status' => 'administrator',
            'can_manage_topics' => true,
            'can_delete_messages' => true,
            'can_pin_messages' => true,
        ],
        ...$overrides,
    ]);
}

// The URL generator took its root from APP_URL at boot; set it as a public
// HTTPS deployment would have it.
beforeEach(function () {
    url()->forceRootUrl('https://support.example.com');
    url()->forceScheme('https');
});

it('checks everything, sets the webhook with the secret, and posts the dashboard', function () {
    healthyGroup();

    $this->artisan('threadlive:setup')
        ->expectsOutputToContain('@support_test_bot')
        ->expectsOutputToContain('“Support”, topics on')
        ->assertSuccessful();

    expect(telegramCalls('setWebhook')[0])->toMatchArray([
        'url' => 'https://support.example.com/threadlive/telegram/webhook',
        'secret_token' => 'webhook-secret',
        'allowed_updates' => ['message', 'edited_message', 'callback_query'],
    ])
        ->and(telegramCalls('sendMessage')[0]['text'])->toContain('Waiting for a reply (0)')
        ->and(telegramCalls('pinChatMessage'))->toHaveCount(1);
});

it('says Topics are off, and sets nothing', function () {
    healthyGroup(['getChat' => ['id' => -1001234567890, 'title' => 'Support', 'type' => 'supergroup', 'is_forum' => false]]);

    $this->artisan('threadlive:setup')
        ->expectsOutputToContain('Topics are off in “Support”')
        ->assertFailed();

    expect(telegramCalls('setWebhook'))->toBeEmpty();
});

it('gives the new id when the group became a supergroup', function () {
    healthyGroup(['getChat' => [
        'error' => 'Bad Request: group chat was upgraded to a supergroup chat',
        'parameters' => ['migrate_to_chat_id' => -1009876543210],
    ]]);

    $this->artisan('threadlive:setup')
        ->expectsOutputToContain('Set THREADLIVE_GROUP_ID=-1009876543210')
        ->assertFailed();
});

it('lists the admin rights the bot is missing', function () {
    healthyGroup(['getChatMember' => ['status' => 'administrator', 'can_manage_topics' => true]]);

    $this->artisan('threadlive:setup')
        ->expectsOutputToContain('these admin rights: Delete messages, Pin messages')
        ->assertFailed();
});

it('refuses a webhook address that is not HTTPS, pointing at a tunnel', function () {
    url()->forceRootUrl('http://127.0.0.1:8020');
    url()->forceScheme('http');
    healthyGroup();

    $this->artisan('threadlive:setup')
        ->expectsOutputToContain('Telegram calls HTTPS addresses only')
        ->assertFailed();

    expect(telegramCalls('setWebhook'))->toBeEmpty();
});

it('explains an address Telegram cannot reach, pointing at --local, instead of failing with a trace', function () {
    url()->forceRootUrl('https://psm.local');
    healthyGroup(['setWebhook' => ['error' => 'Bad Request: bad webhook: Failed to resolve host: Name or service not known']]);

    $this->artisan('threadlive:setup')
        // One message: the address, why, and --local (one line of output
        // meets one expectation, so only its start is checked).
        ->expectsOutputToContain('Telegram cannot use https://psm.local/threadlive/telegram/webhook')
        ->assertFailed();

    expect(telegramCalls('pinChatMessage'))->toBeEmpty();
});

it('says so when there is no token', function () {
    config()->set('threadlive.bot_token', '');

    $this->artisan('threadlive:setup')->expectsOutputToContain('THREADLIVE_BOT_TOKEN is empty')->assertFailed();
});
