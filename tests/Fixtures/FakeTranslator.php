<?php

declare(strict_types=1);

namespace Threadlive\Tests\Fixtures;

use RuntimeException;
use Threadlive\Contracts\Translator;
use Threadlive\Translation\Translation;

/** "Hola…" is Spanish, everything else English; a translation reads "[es] text". */
final class FakeTranslator implements Translator
{
    public static bool $down = false;

    /** @var list<array{string, string}> text, target */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$down = false;
        self::$calls = [];
    }

    public function translate(string $text, string $target, ?string $source = null): ?Translation
    {
        self::$calls[] = [$text, $target];

        if (self::$down) {
            throw new RuntimeException('Translator down');
        }

        $detected = str_starts_with($text, 'Hola') ? 'es' : 'en';

        return new Translation("[{$target}] {$text}", $detected, $target);
    }
}
