<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Support\Facades\File;

/**
 * What the group's admins set from Telegram (/settings): the company text,
 * the support hours, the assistant on or off (and when it answers), and the
 * managers called by name. Kept in storage/app/threadlive/settings.json,
 * like the FAQ; each one overrides its config value (assistant.about,
 * hours, assistant.enabled / assistant.first, mention) until reset. The
 * config stays the developer's default.
 */
final class GroupSettings
{
    public const ABOUT = 'about';

    public const HOURS = 'hours';

    /** {enabled?: bool, first?: 'always'|'off_hours'} */
    public const ASSISTANT = 'assistant';

    /** list of "@username" */
    public const MENTION = 'mention';

    public const MAX_ABOUT = 3000;

    /**
     * Read again whenever the file changes: a queue worker or the listener
     * lives for hours, and an admin may change a setting meanwhile.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $loaded = null;

    private static int|false $loadedAt = false;

    public static function path(): string
    {
        return storage_path('app/threadlive/settings.json');
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function set(string $key, mixed $value): void
    {
        $all = self::all();
        $all[$key] = $value;
        self::write($all);
    }

    public static function forget(string $key): void
    {
        $all = self::all();
        unset($all[$key]);
        self::write($all);
    }

    /** The company text set from Telegram; null: the config's. */
    public static function about(): ?string
    {
        $about = self::get(self::ABOUT);

        return is_string($about) && trim($about) !== '' ? $about : null;
    }

    /**
     * The hours set from Telegram; null: the config's.
     *
     * @return array{from: string, to: string, tz: string}|null
     */
    public static function hours(): ?array
    {
        $hours = self::get(self::HOURS);

        return is_array($hours) && is_string($hours['from'] ?? null) && is_string($hours['to'] ?? null) && is_string($hours['tz'] ?? null)
            ? ['from' => $hours['from'], 'to' => $hours['to'], 'tz' => $hours['tz']]
            : null;
    }

    /** The assistant switched on or off from Telegram; null: the config's. */
    public static function assistantEnabled(): ?bool
    {
        $assistant = self::get(self::ASSISTANT);
        $enabled = is_array($assistant) ? ($assistant['enabled'] ?? null) : null;

        return is_bool($enabled) ? $enabled : null;
    }

    /**
     * When the assistant answers, set from Telegram; null: the config's.
     *
     * @return 'always'|'off_hours'|null
     */
    public static function assistantFirst(): ?string
    {
        $assistant = self::get(self::ASSISTANT);
        $first = is_array($assistant) ? ($assistant['first'] ?? null) : null;

        return $first === 'always' || $first === 'off_hours' ? $first : null;
    }

    /** One of the assistant's settings: 'enabled' or 'first'. */
    public static function setAssistant(string $key, bool|string $value): void
    {
        $assistant = self::get(self::ASSISTANT);
        $assistant = is_array($assistant) ? $assistant : [];
        $assistant[$key] = $value;
        self::set(self::ASSISTANT, $assistant);
    }

    /**
     * The managers called by name, set from Telegram (maybe none); null:
     * the config's.
     *
     * @return list<string>|null
     */
    public static function mentions(): ?array
    {
        $mentions = self::get(self::MENTION);

        return is_array($mentions) ? array_values(array_filter($mentions, 'is_string')) : null;
    }

    /** For tests, and after a write from another process. */
    public static function flush(): void
    {
        self::$loaded = null;
        self::$loadedAt = false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        $path = self::path();
        clearstatcache(true, $path);
        $modified = is_file($path) ? filemtime($path) : false;

        if (self::$loaded !== null && $modified === self::$loadedAt) {
            return self::$loaded;
        }

        $data = $modified !== false ? json_decode((string) file_get_contents($path), true) : null;
        self::$loadedAt = $modified;

        return self::$loaded = is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $all
     */
    private static function write(array $all): void
    {
        $path = self::path();
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temporary, (string) json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::move($temporary, $path);
        self::$loaded = $all;
        clearstatcache(true, $path);
        self::$loadedAt = filemtime($path);
    }
}
