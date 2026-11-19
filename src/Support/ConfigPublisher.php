<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * config/threadlive.php in the application: created from the package's, and
 * after a package update given the settings it brought — each with the
 * comment above it — without touching the values already there.
 */
final class ConfigPublisher
{
    private const SOURCE = __DIR__.'/../../config/threadlive.php';

    public function __construct(private readonly Filesystem $files) {}

    /**
     * Settings inside a group (attachments.types) that the published file
     * lacks. Not written in: editing inside someone's array is where a file
     * gets broken. Named, so they can be copied by hand; the package uses
     * its defaults for them meanwhile.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function missingNested(array $values): array
    {
        $defaults = $this->load($this->files->get(self::SOURCE)) ?? [];
        $missing = [];

        foreach ($defaults as $key => $default) {
            if (! is_array($default) || array_is_list($default) || ! is_array($values[$key] ?? null)) {
                continue;
            }
            foreach (array_keys(array_diff_key($default, $values[$key])) as $nested) {
                $missing[] = "{$key}.{$nested}";
            }
        }

        return $missing;
    }

    /**
     * @return array{status: 'published'|'updated'|'current'|'failed', added: list<string>, unknown: list<string>, nested: list<string>, backup: string|null, error: string|null}
     */
    public function sync(bool $force = false): array
    {
        $target = config_path('threadlive.php');
        $result = ['status' => 'current', 'added' => [], 'unknown' => [], 'nested' => [], 'backup' => null, 'error' => null];

        if (! $this->files->exists($target) || $force) {
            if ($this->files->exists($target)) {
                $result['backup'] = $target.'.bak';
                $this->files->copy($target, $result['backup']);
            }

            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->put($target, $this->packageSource());

            return ['status' => 'published'] + $result;
        }

        $published = $this->files->get($target);
        $values = $this->load($published);

        if ($values === null) {
            return ['status' => 'failed', 'error' => 'config/threadlive.php does not return an array; fix it or run with --force.'] + $result;
        }

        $blocks = self::blocks($this->packageSource(forAppending: true));
        $missing = array_diff_key($blocks, $values);
        $result['unknown'] = array_map('strval', array_keys(array_diff_key($values, $blocks)));
        $result['nested'] = $this->missingNested($values);

        if ($missing === []) {
            return $result;
        }

        $updated = self::append($published, $missing);

        if ($updated === null || $this->load($updated) === null) {
            return ['status' => 'failed', 'error' => 'Could not add '.implode(', ', array_keys($missing)).' to config/threadlive.php; add them by hand from the package\'s config/threadlive.php.'] + $result;
        }

        $this->files->put($target, $updated);

        return ['status' => 'updated', 'added' => array_keys($missing)] + $result;
    }

    /**
     * The package's config as the application gets it: pointed at the app's
     * ChatHost once one is published. For appending, the default host class
     * is spelled out in full: the published file has no `use` for it.
     */
    private function packageSource(bool $forAppending = false): string
    {
        $source = $this->files->get(self::SOURCE);

        if ($forAppending) {
            return str_replace('=> GuardChatHost::class', '=> \\Threadlive\\Host\\GuardChatHost::class', $source);
        }

        if ($this->files->exists(app_path('Threadlive/ChatHost.php'))) {
            return str_replace(
                ["use Threadlive\\Host\\GuardChatHost;\n\n", "'host' => GuardChatHost::class,"],
                ['', "'host' => App\\Threadlive\\ChatHost::class,"],
                $source,
            );
        }

        return $source;
    }

    /**
     * Each top-level setting of a config file, with the comments above it,
     * by key — found with PHP's own tokenizer, so a comma or a bracket inside
     * a value or a comment is not mistaken for the end of one.
     *
     * @return array<string, string>
     */
    public static function blocks(string $source): array
    {
        $blocks = [];
        $offset = 0;
        $depth = 0;
        $started = false;
        $blockStart = 0;
        /** @var string|null $previous the last string at the top level, maybe a key */
        $previous = null;
        /** @var string|null $key the setting being read */
        $key = null;

        foreach (token_get_all($source) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            $length = strlen($text);

            if (! $started) {
                $started = $id === T_RETURN;
                $offset += $length;

                continue;
            }

            if ($text === '[' || $text === '(') {
                if ($depth === 0) {
                    $blockStart = $offset + 1;
                }
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                $depth--;
            } elseif ($depth === 1 && ! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($id === T_DOUBLE_ARROW && $key === null) {
                    $key = $previous;
                } elseif ($text === ',' && $key !== null) {
                    $blocks[$key] = substr($source, $blockStart, $offset + 1 - $blockStart);
                    $key = null;
                    $blockStart = $offset + 1;
                }

                $previous = $id === T_CONSTANT_ENCAPSED_STRING ? trim($text, '\'"') : null;
            }

            $offset += $length;
        }

        return $blocks;
    }

    /**
     * The blocks added before the closing `];` of the published file.
     *
     * @param  array<string, string>  $blocks
     */
    private static function append(string $published, array $blocks): ?string
    {
        $end = strrpos($published, '];');
        if ($end === false) {
            return null;
        }

        $head = rtrim(substr($published, 0, $end));
        if (! str_ends_with($head, ',') && ! str_ends_with($head, '[')) {
            $head .= ',';
        }

        $added = '';
        foreach ($blocks as $block) {
            $added .= "\n".rtrim(ltrim($block, "\r\n"))."\n";
        }

        return $head."\n".$added."\n];\n";
    }

    /**
     * The array a config source returns, or null when it does not load.
     *
     * @return array<mixed>|null
     */
    private function load(string $source): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'threadlive-config');
        if ($path === false) {
            return null;
        }

        try {
            file_put_contents($path, $source);
            $values = (static fn (string $file) => require $file)($path);

            return is_array($values) ? $values : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($path);
        }
    }
}
