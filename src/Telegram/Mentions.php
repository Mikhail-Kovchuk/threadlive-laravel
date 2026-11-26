<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

/**
 * Calling managers by name: a mention notifies them even in a muted group.
 */
final class Mentions
{
    /** "@maria @oleh" from config('threadlive.mention'); '' for none. */
    public static function fromConfig(): string
    {
        $names = config('threadlive.mention', []);
        $mentions = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if (is_string($name) && preg_match('/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/', trim($name), $m) === 1) {
                $mentions['@'.$m[1]] = true;
            }
        }

        return implode(' ', array_keys($mentions));
    }

    /** A mention of one user by id — works without a username. */
    public static function user(int $id, string $name): string
    {
        return '<a href="tg://user?id='.$id.'">'.e($name).'</a>';
    }
}
