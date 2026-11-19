<?php

declare(strict_types=1);

namespace Threadlive\Translation;

final class Languages
{
    /** 'EN-US' → 'en', 'es' → 'es'. */
    public static function base(string $code): string
    {
        return strtolower(substr(trim($code), 0, 2));
    }

    /** 'es' → 'ES', as the topic shows it. */
    public static function label(string $code): string
    {
        return strtoupper(self::base($code));
    }
}
