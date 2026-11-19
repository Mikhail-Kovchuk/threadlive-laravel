<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Http\Client\Factory as HttpFactory;

/** The Bot API over Laravel's HTTP client, so Http::fake() covers every call. */
final class TelegramClient
{
    private const API = 'https://api.telegram.org';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $token,
    ) {}

    /**
     * @param  array<string, mixed>  $params  null values are left out
     * @param  int  $timeout  seconds; longer for getUpdates, which holds the request open
     *
     * @throws TelegramException
     */
    public function call(string $method, array $params = [], int $timeout = 20): mixed
    {
        if ($this->token === '') {
            throw new TelegramException('THREADLIVE_BOT_TOKEN is not set.');
        }

        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->post(self::API."/bot{$this->token}/{$method}", array_filter($params, fn ($value) => $value !== null));

        $data = $response->json();

        if (! is_array($data) || ($data['ok'] ?? false) !== true) {
            throw TelegramException::fromResponse($method, $response->status(), is_array($data) ? $data : []);
        }

        return $data['result'] ?? null;
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>|null  $keyboard
     * @return int the message id
     *
     * @throws TelegramException
     */
    public function sendMessage(string $chatId, string $html, ?int $threadId = null, bool $silent = true, ?array $keyboard = null): int
    {
        $result = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'message_thread_id' => $threadId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_notification' => $silent,
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $keyboard === null ? null : ['inline_keyboard' => $keyboard],
        ]);

        return is_array($result) && is_int($result['message_id'] ?? null) ? $result['message_id'] : 0;
    }
}
