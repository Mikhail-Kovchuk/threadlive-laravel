<?php

declare(strict_types=1);

namespace Threadlive\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Google's Gemini API (generateContent), asked for JSON that fits a schema.
 * Shared by the translation driver and the assistant. The key is
 * THREADLIVE_GEMINI_KEY; the model and thinking level are config('threadlive.gemini').
 */
final class GeminiClient
{
    private const API_ROOT = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $key,
        private readonly string $model,
        /** Gemini 3's thinkingLevel ('low', 'high'…); null sends none (older models). */
        private readonly ?string $thinking = null,
    ) {}

    /** The `gemini` block; $model in place of its model when given. */
    public static function fromConfig(HttpFactory $http, ?string $model = null): self
    {
        $thinking = config('threadlive.gemini.thinking');
        $model = $model !== null && $model !== '' ? $model : config('threadlive.gemini.model');

        return new self(
            $http,
            trim((string) config('threadlive.gemini.key')),
            is_string($model) && $model !== '' ? $model : 'gemini-3.5-flash-lite',
            is_string($thinking) && $thinking !== '' ? $thinking : null,
        );
    }

    public function hasKey(): bool
    {
        return $this->key !== '';
    }

    /**
     * $system as the instructions, $text as the one user turn, and the
     * answer decoded. The text is data for the instructions to work on —
     * kept out of $system, so what a visitor wrote is never read as one.
     *
     * @param  array<string, mixed>  $schema  OpenAPI-style response schema
     * @return array<string, mixed>
     *
     * @throws GeminiException
     */
    public function json(string $system, string $text, array $schema, int $timeout = 20): array
    {
        if ($this->key === '') {
            throw new GeminiException('THREADLIVE_GEMINI_KEY is not set.');
        }

        $config = ['responseMimeType' => 'application/json', 'responseSchema' => $schema];
        if ($this->thinking !== null) {
            $config['thinkingConfig'] = ['thinkingLevel' => $this->thinking];
        }

        try {
            $response = $this->http
                // A header, not ?key=: a key in a URL ends up in logs.
                ->withHeaders(['x-goog-api-key' => $this->key])
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post(self::API_ROOT.'/'.rawurlencode($this->model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
                    'generationConfig' => $config,
                ]);
        } catch (ConnectionException $e) {
            throw new GeminiException('Gemini is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            // Google's message, never the request: that holds what visitors wrote.
            $message = $response->json('error.message');
            throw new GeminiException(
                'Gemini answered '.$response->status().(is_string($message) ? ': '.mb_substr($message, 0, 300) : '.'),
                $response->status(),
            );
        }

        $parts = $response->json('candidates.0.content.parts');
        if (! is_array($parts)) {
            // A blocked prompt: no candidates, a promptFeedback instead.
            $reason = $response->json('promptFeedback.blockReason') ?? $response->json('candidates.0.finishReason');
            throw new GeminiException('Gemini gave no answer'.(is_string($reason) ? ' ('.$reason.')' : '').'.');
        }

        $answer = '';
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && ($part['thought'] ?? false) !== true) {
                $answer .= $part['text'];
            }
        }

        $decoded = json_decode($answer, true);
        if (! is_array($decoded)) {
            throw new GeminiException('Gemini\'s answer is not the JSON asked for.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
