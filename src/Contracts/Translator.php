<?php

declare(strict_types=1);

namespace Threadlive\Contracts;

use Threadlive\Translation\Translation;

/**
 * A translation service. The package ships DeepL
 * (config threadlive.translation.driver = 'deepl'); any other is a class of
 * the application implementing this, named in that setting.
 *
 * Languages are lowercase two-letter codes: 'en', 'es', 'ru'.
 */
interface Translator
{
    /**
     * $text in $target, with the language it was written in (detected when
     * $source is null). Null when the service cannot answer now (a quota
     * used up); an exception for anything else — both leave the message
     * untranslated, never lost.
     */
    public function translate(string $text, string $target, ?string $source = null): ?Translation;
}
