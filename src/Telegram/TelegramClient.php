<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

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

        $response = $this->guard($method, fn () => $this->http
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->post(self::API."/bot{$this->token}/{$method}", array_filter($params, fn ($value) => $value !== null)));

        $data = $response->json();

        if (! is_array($data) || ($data['ok'] ?? false) !== true) {
            throw TelegramException::fromResponse($method, $response->status(), is_array($data) ? $data : []);
        }

        return $data['result'] ?? null;
    }

    /**
     * A call with a file in it (sendPhoto, sendDocument): multipart, the
     * other params as fields (arrays as JSON, as the Bot API takes them).
     *
     * @param  array<string, mixed>  $params
     * @param  array{0: string, 1: string, 2: string}  $file  field, contents, filename
     *
     * @throws TelegramException
     */
    public function upload(string $method, array $params, array $file): mixed
    {
        if ($this->token === '') {
            throw new TelegramException('THREADLIVE_BOT_TOKEN is not set.');
        }

        $fields = [];
        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }
            $fields[$name] = match (true) {
                is_array($value) => (string) json_encode($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        $response = $this->guard($method, fn () => $this->http
            ->acceptJson()
            ->timeout(120)
            ->attach($file[0], $file[1], $file[2])
            ->post(self::API."/bot{$this->token}/{$method}", $fields));

        $data = $response->json();

        if (! is_array($data) || ($data['ok'] ?? false) !== true) {
            throw TelegramException::fromResponse($method, $response->status(), is_array($data) ? $data : []);
        }

        return $data['result'] ?? null;
    }

    /**
     * A file someone sent in the group. Telegram lets a bot fetch up to 20 MB.
     *
     * @return array{contents: string, path: string}
     *
     * @throws TelegramException
     */
    public function download(string $fileId): array
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);
        $path = is_array($file) && is_string($file['file_path'] ?? null) ? $file['file_path'] : null;

        if ($path === null) {
            throw new TelegramException('Telegram getFile: no file_path in the answer.');
        }

        $response = $this->guard('download', fn () => $this->http->timeout(120)->get(self::API."/file/bot{$this->token}/{$path}"));

        if (! $response->successful()) {
            throw new TelegramException("Telegram file download: HTTP {$response->status()}", $response->status());
        }

        return ['contents' => $response->body(), 'path' => $path];
    }

    /**
     * A network failure (timeout, refused connection) as a TelegramException,
     * like any other failed call — so a listener retries it instead of dying
     * — and without the bot token, which Guzzle puts in its message with the
     * URL: that message ends up in logs and error trackers.
     *
     * @param  callable(): Response  $send
     *
     * @throws TelegramException
     */
    private function guard(string $method, callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw new TelegramException("Telegram {$method}: connection failed (".str_replace($this->token, '***', $e->getMessage()).')');
        }
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
