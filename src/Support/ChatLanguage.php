<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Http\Request;
use Threadlive\Translation\Languages;

/**
 * Which language the chat speaks to a visitor (config threadlive.language):
 * their browser's, when `browser` is on and there is a text in it, else
 * `default`. The widget picks its own texts from the same list (bootstrap's
 * `languages`), the FAQ its answers.
 */
final class ChatLanguage
{
    public static function default(): string
    {
        $default = config('threadlive.language.default', 'en');

        return Languages::base(is_string($default) && $default !== '' ? $default : 'en');
    }

    /**
     * The visitor's languages in their order of preference, then the
     * default — or only the default with `browser` off. Two-letter codes.
     *
     * @return list<string>
     */
    public static function wanted(Request $request): array
    {
        $wanted = [];

        if (config('threadlive.language.browser', true) !== false) {
            foreach ($request->getLanguages() as $language) {
                // 'uk', 'de_DE' (Symfony writes de-DE so); not 'fil' or '*'.
                if (preg_match('/^[a-z]{2}(?:[_-]|$)/i', $language) === 1) {
                    $wanted[] = Languages::base($language);
                }
            }
        }

        $wanted[] = self::default();

        return array_values(array_unique($wanted));
    }

    /**
     * The first of $wanted that is in $has; else the default, if there;
     * else the first of $has.
     *
     * @param  non-empty-list<string>  $has
     * @param  list<string>  $wanted
     */
    public static function pick(array $has, array $wanted): string
    {
        foreach ([...$wanted, self::default()] as $language) {
            if (in_array($language, $has, true)) {
                return $language;
            }
        }

        return $has[0];
    }
}
