<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Threadlive\ChatUser;
use Threadlive\Models\Conversation;
use Threadlive\Tests\Fixtures\FakeChatHost;

const SITEVERIFY = 'challenges.cloudflare.com/turnstile/v0/siteverify';

beforeEach(function () {
    config()->set('threadlive.turnstile_site_key', '1x00000000000000000000AA');
    config()->set('threadlive.turnstile_secret', 'test-secret');
});

it('gives the site key to a guest with no conversation, and to no one else', function () {
    expect($this->getJson('/threadlive/bootstrap')->json('turnstile_site_key'))->toBe('1x00000000000000000000AA');

    Http::fake([SITEVERIFY => Http::response(['success' => true])]);
    $token = sendMessage('Hello', null, ['turnstile_token' => 'ok'])->assertCreated()->json('visitor_token');
    expect($this->getJson('/threadlive/bootstrap', ['X-Threadlive-Token' => $token])->json('turnstile_site_key'))->toBeNull();

    FakeChatHost::$user = new ChatUser('42', 'Anna');
    expect($this->getJson('/threadlive/bootstrap')->json('turnstile_site_key'))->toBeNull();
});

it('is off without both keys', function () {
    config()->set('threadlive.turnstile_secret', '');

    expect($this->getJson('/threadlive/bootstrap')->json('turnstile_site_key'))->toBeNull();
    sendMessage('Hello')->assertCreated();
});

it('starts a conversation only with a token Cloudflare accepts', function () {
    Http::fake([SITEVERIFY => fn (Request $request) => Http::response(['success' => $request['response'] === 'good'])]);

    sendMessage('Hello')->assertUnprocessable()
        ->assertJsonPath('message', 'We could not check that you are not a robot. Try sending again.');
    sendMessage('Hello', null, ['turnstile_token' => 'bad'])->assertUnprocessable();
    expect(Conversation::query()->count())->toBe(0);

    sendMessage('Hello', null, ['turnstile_token' => 'good'])->assertCreated();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), SITEVERIFY)
        && $request['secret'] === 'test-secret'
        && $request['response'] === 'good'
        && $request['remoteip'] === '127.0.0.1');
});

it('asks nothing of a visitor already in a conversation, or signed in', function () {
    Http::fake([SITEVERIFY => Http::response(['success' => true])]);
    $token = sendMessage('Hello', null, ['turnstile_token' => 'good'])->json('visitor_token');

    Http::fake([SITEVERIFY => Http::response(['success' => false])]);
    sendMessage('Me again', $token)->assertCreated();

    FakeChatHost::$user = new ChatUser('42', 'Anna');
    sendMessage('Signed in')->assertCreated();
});

it('lets the message through when Cloudflare cannot be reached', function () {
    Http::fake([SITEVERIFY => fn () => throw new ConnectionException('down')]);

    sendMessage('Hello', null, ['turnstile_token' => 'anything'])->assertCreated();
});
