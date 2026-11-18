<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
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

/**
 * A Bot API that says yes to everything: topics get ids from 100, messages
 * from 1000. `$overrides` answers a method differently — a result, or a
 * callable taking the call's params and the how-many-th call it is (1-based)
 * and returning a result, or an `error` (description) to fail with.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeTelegram(array $overrides = []): void
{
    $threadIds = 100;
    $messageIds = 1000;
    $calls = [];

    Http::fake(['api.telegram.org/*' => function (Request $request) use (&$threadIds, &$messageIds, &$calls, $overrides) {
        $method = Str::afterLast($request->url(), '/');
        $calls[$method] = ($calls[$method] ?? 0) + 1;

        if (array_key_exists($method, $overrides)) {
            $answer = $overrides[$method];
            $answer = is_callable($answer) ? $answer($request->data(), $calls[$method]) : $answer;

            if (is_array($answer) && array_key_exists('error', $answer)) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => $answer['error']], 400);
            }

            if ($answer !== null) {
                return Http::response(['ok' => true, 'result' => $answer]);
            }
        }

        return Http::response(['ok' => true, 'result' => match ($method) {
            'createForumTopic' => ['message_thread_id' => ++$threadIds, 'name' => $request->data()['name'] ?? ''],
            'sendMessage' => ['message_id' => ++$messageIds],
            default => true,
        }]);
    }]);
}

/**
 * The params of every call to one Bot API method, in order.
 *
 * @return list<array<string, mixed>>
 */
function telegramCalls(string $method): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => Str::afterLast($request->url(), '/') === $method)
        ->map(fn (Request $request) => $request->data())
        ->values()
        ->all();
}

/**
 * POST an update to the webhook as Telegram would.
 *
 * @param  array<string, mixed>  $update
 * @return TestResponse<Response>
 */
function telegramUpdate(array $update, string $secret = 'webhook-secret'): TestResponse
{
    return test()->postJson('/threadlive/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
}

/**
 * A message from a manager in a topic of the group.
 *
 * @return array<string, mixed>
 */
function managerMessage(int $thread, string $text, int $messageId = 5000, string $name = 'Maria', int $userId = 77): array
{
    return [
        'message_id' => $messageId,
        'message_thread_id' => $thread,
        'is_topic_message' => true,
        'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => $name],
        'text' => $text,
    ];
}
