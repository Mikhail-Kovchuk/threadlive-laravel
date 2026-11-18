<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Threadlive\Host\GuardChatHost;
use Threadlive\Models\Conversation;

beforeEach(function () {
    // A token guard, the way JWT and Sanctum work: no session, no middleware.
    Auth::viaRequest('test-token', fn (Request $request) => $request->bearerToken() === 'valid'
        ? new GenericUser(['id' => 7, 'name' => 'Anna', 'email' => 'anna@example.com'])
        : null);
    config()->set('auth.guards.widget', ['driver' => 'test-token']);
    config()->set('threadlive.guard', 'widget');
    config()->set('threadlive.host', GuardChatHost::class);
});

it('knows a signed-in visitor from the configured guard', function () {
    $this->postJson('/threadlive/messages', ['body' => 'Hi', 'client_msg_id' => (string) Str::uuid()], [
        'Authorization' => 'Bearer valid',
    ])->assertCreated()->assertJsonMissingPath('visitor_token');

    $conversation = Conversation::query()->firstOrFail();
    expect($conversation->user_ref)->toBe('7')
        ->and($conversation->name)->toBe('Anna')
        ->and($conversation->email)->toBe('anna@example.com');
});

it('treats a request the guard does not recognise as a guest', function () {
    $this->postJson('/threadlive/messages', ['body' => 'Hi', 'client_msg_id' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonStructure(['visitor_token']);

    expect(Conversation::query()->firstOrFail()->user_ref)->toBeNull();
});
