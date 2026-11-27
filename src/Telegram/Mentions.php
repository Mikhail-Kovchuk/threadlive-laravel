<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Threadlive\Support\GroupSettings;

/**
 * Calling managers by name: a mention notifies them even in a muted group.
 */
final class Mentions
{
    private const USERNAME = '/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/';

    /**
     * "@maria @oleh": who a handoff nobody has and a waiting alert call —
     * as set in ⚙️ Settings → 👥 Mentions, else config('threadlive.mention').
     * '' for none.
     */
    public static function managers(): string
    {
        return implode(' ', self::list());
    }

    /**
     * @return list<string> "@maria", …
     */
    public static function list(): array
    {
        $names = GroupSettings::mentions() ?? config('threadlive.mention', []);
        $mentions = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if (is_string($name) && preg_match(self::USERNAME, trim($name), $m) === 1) {
                $mentions['@'.$m[1]] = true;
            }
        }

        return array_keys($mentions);
    }

    /**
     * What an admin wrote — "@maria @oleh", "maria, oleh" — as usernames;
     * "none" or "-" for nobody.
     *
     * @return array{names: list<string>, wrong: list<string>}
     */
    public static function parse(string $text): array
    {
        $text = trim($text);
        if (in_array(mb_strtolower($text), ['none', 'nobody', '-', '—'], true)) {
            return ['names' => [], 'wrong' => []];
        }

        $names = [];
        $wrong = [];
        foreach (preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match(self::USERNAME, $word, $m) === 1) {
                $names['@'.$m[1]] = true;
            } else {
                $wrong[] = $word;
            }
        }

        return ['names' => array_keys($names), 'wrong' => $wrong];
    }

    /** A mention of one user by id — works without a username. */
    public static function user(int $id, string $name): string
    {
        return '<a href="tg://user?id='.$id.'">'.e($name).'</a>';
    }
}
