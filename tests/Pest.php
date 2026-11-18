<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Threadlive\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * POST a visitor message as the widget would.
 *
 * @param  array<string, mixed>  $extra
 * @return TestResponse<JsonResponse>
 */
function sendMessage(string $body, ?string $token = null, array $extra = []): TestResponse
{
    $headers = $token === null ? [] : ['X-Threadlive-Token' => $token];

    return test()->postJson('/threadlive/messages', [
        'body' => $body,
        'client_msg_id' => (string) Str::uuid(),
        ...$extra,
    ], $headers);
}
