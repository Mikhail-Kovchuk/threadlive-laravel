<?php

declare(strict_types=1);

namespace Threadlive\Translation;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Threadlive\Contracts\Translator;

/**
 * DeepL API. A Free key (ending in :fx) goes to api-free.deepl.com, a Pro
 * key to api.deepl.com. The key is THREADLIVE_TRANSLATION_KEY.
 */
final class DeepLTranslator implements Translator
{
    /** DeepL's "quota exceeded": the month's free characters are used up. */
    private const QUOTA_EXCEEDED = 456;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $key,
    ) {}

    public function translate(string $text, string $target, ?string $source = null): ?Translation
    {
        if ($this->key === '') {
            throw new RuntimeException('THREADLIVE_TRANSLATION_KEY is not set.');
        }

        $base = str_ends_with($this->key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';

        $response = $this->http
            ->withHeaders(['Authorization' => 'DeepL-Auth-Key '.$this->key])
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post($base.'/v2/translate', array_filter([
                'text' => [$text],
                'target_lang' => self::targetCode($target),
                'source_lang' => $source === null ? null : strtoupper(Languages::base($source)),
            ]));

        if ($response->status() === self::QUOTA_EXCEEDED) {
            return null;
        }

        $response->throw();

        $translation = $response->json('translations.0');

        if (! is_array($translation) || ! is_string($translation['text'] ?? null)) {
            return null;
        }

        $detected = is_string($translation['detected_source_language'] ?? null)
            ? $translation['detected_source_language']
            : ($source ?? '');

        return new Translation($translation['text'], Languages::base($detected), Languages::base($target));
    }

    /** DeepL wants a variant for some targets: plain EN and PT are deprecated. */
    private static function targetCode(string $language): string
    {
        return match (Languages::base($language)) {
            'en' => 'EN-US',
            'pt' => 'PT-BR',
            default => strtoupper(Languages::base($language)),
        };
    }
}
