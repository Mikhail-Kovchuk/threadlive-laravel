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

    private const NAMES = [
        'ar' => 'Arabic', 'bg' => 'Bulgarian', 'cs' => 'Czech', 'da' => 'Danish', 'de' => 'German',
        'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'et' => 'Estonian', 'fi' => 'Finnish',
        'fr' => 'French', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hu' => 'Hungarian', 'id' => 'Indonesian',
        'it' => 'Italian', 'ja' => 'Japanese', 'ka' => 'Georgian', 'kk' => 'Kazakh', 'ko' => 'Korean',
        'lt' => 'Lithuanian', 'lv' => 'Latvian', 'nb' => 'Norwegian', 'nl' => 'Dutch', 'pl' => 'Polish',
        'pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian', 'sk' => 'Slovak', 'sl' => 'Slovenian',
        'sr' => 'Serbian', 'sv' => 'Swedish', 'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian',
        'uz' => 'Uzbek', 'vi' => 'Vietnamese', 'zh' => 'Chinese',
    ];

    /** 'es' → 'Spanish', for an AI to read; an unknown code stays a code ('XX'). */
    public static function name(string $code): string
    {
        return self::NAMES[self::base($code)] ?? self::label($code);
    }
}
