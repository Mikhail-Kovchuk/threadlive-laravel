<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use JsonException;
use Threadlive\Translation\Languages;

/**
 * The quick answers on the widget's first screen, from a faq.json:
 *
 *   [
 *     {"id": "invoice", "question": "Where is my invoice?", "answer": "Account → Invoices."},
 *     {"id": "hours", "question": {"en": "When do you answer?", "uk": "Коли ви відповідаєте?"},
 *      "answer": {"en": "…", "uk": "…"}, "show": false}
 *   ]
 *
 * A plain string is in config('threadlive.language.default'). Stored at
 * config('threadlive.faq.path') with every text as {language: text}, which
 * is also what /faq gives back. Plain text; line breaks are kept.
 *
 * @phpstan-type Entry array{id: string, question: non-empty-array<string, string>, answer: non-empty-array<string, string>, show: bool}
 */
final class Faq
{
    public const MAX_BYTES = 1048576;

    public const MAX_ENTRIES = 200;

    private const MAX_QUESTION = 300;

    private const MAX_ANSWER = 4000;

    /** Errors shown at once; the rest are counted. */
    private const ERRORS_SHOWN = 10;

    /**
     * What is stored. A file that cannot be read gives no FAQ, not an error
     * for the visitor.
     *
     * @return list<Entry>
     */
    public static function entries(): array
    {
        $path = self::path();

        if ($path === null || ! is_file($path)) {
            return [];
        }

        [$entries, $errors] = self::parse((string) file_get_contents($path));

        return $errors === [] ? $entries : [];
    }

    /**
     * A faq.json checked whole: the entries, or what is wrong with it — by
     * entry number, as an editor finds it.
     *
     * @return array{list<Entry>, list<string>}
     */
    public static function parse(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            return [[], ['The file is over 1 MB.']];
        }

        try {
            $data = json_decode(self::withoutBom($json), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [[], ['This is not valid JSON: '.$e->getMessage().'.']];
        }

        if (! is_array($data) || ! array_is_list($data)) {
            return [[], ['The file must be a list: [ {"id": …, "question": …, "answer": …}, … ].']];
        }

        if (count($data) > self::MAX_ENTRIES) {
            return [[], ['Up to '.self::MAX_ENTRIES.' questions in one file.']];
        }

        $entries = [];
        $errors = [];
        $ids = [];

        foreach ($data as $index => $item) {
            $where = 'Entry '.($index + 1);

            if (! is_array($item)) {
                $errors[] = "{$where}: must be {\"id\": …, \"question\": …, \"answer\": …}.";

                continue;
            }

            $id = $item['id'] ?? null;
            if (! is_string($id) || preg_match('/^[a-z0-9_-]{1,64}$/', $id) !== 1) {
                $errors[] = "{$where}: id must be up to 64 small letters, digits, - and _.";

                continue;
            }

            $where .= " ({$id})";
            if (isset($ids[$id])) {
                $errors[] = "{$where}: the id is used twice.";

                continue;
            }
            $ids[$id] = true;

            $question = self::texts($item['question'] ?? null, self::MAX_QUESTION, "{$where}: question", $errors);
            $answer = self::texts($item['answer'] ?? null, self::MAX_ANSWER, "{$where}: answer", $errors);

            if ($question === null || $answer === null) {
                continue;
            }

            if (array_keys($question) != array_keys($answer)) {
                $errors[] = "{$where}: question and answer must have the same languages.";

                continue;
            }

            if (isset($item['show']) && ! is_bool($item['show'])) {
                $errors[] = "{$where}: show must be true or false.";

                continue;
            }

            $entries[] = ['id' => $id, 'question' => $question, 'answer' => $answer, 'show' => $item['show'] ?? true];
        }

        if (count($errors) > self::ERRORS_SHOWN) {
            $more = count($errors) - self::ERRORS_SHOWN;
            $errors = [...array_slice($errors, 0, self::ERRORS_SHOWN), "… and {$more} more."];
        }

        return [$errors === [] ? $entries : [], $errors];
    }

    /**
     * Written in place of what was there, whole or not at all.
     *
     * @param  list<Entry>  $entries
     */
    public static function save(array $entries): void
    {
        $path = self::path() ?? throw new \RuntimeException('Set threadlive.faq.path to keep the FAQ.');

        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temporary, self::encode($entries));
        File::move($temporary, $path);
    }

    /**
     * The stored FAQ as /faq and threadlive:faq export give it.
     *
     * @param  list<Entry>|null  $entries
     */
    public static function encode(?array $entries = null): string
    {
        $entries ??= self::entries();

        return json_encode(
            array_map(fn (array $entry) => $entry['show'] ? array_diff_key($entry, ['show' => true]) : $entry, $entries),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * What the widget lists, in the visitor's language where there is one.
     *
     * @return list<array{id: string, question: string, answer: string}>
     */
    public static function forWidget(Request $request): array
    {
        $entries = array_values(array_filter(self::entries(), fn (array $entry) => $entry['show']));
        $wanted = ChatLanguage::wanted($request);

        return array_map(function (array $entry) use ($wanted) {
            $language = ChatLanguage::pick(array_keys($entry['question']), $wanted);

            return ['id' => $entry['id'], 'question' => $entry['question'][$language], 'answer' => $entry['answer'][$language]];
        }, $entries);
    }

    /**
     * The questions of these ids, for the managers (the default language).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function questions(array $ids): array
    {
        $questions = [];

        foreach (self::entries() as $entry) {
            if (in_array($entry['id'], $ids, true)) {
                $questions[] = $entry['question'][ChatLanguage::pick(array_keys($entry['question']), [])];
            }
        }

        return $questions;
    }

    /**
     * The languages a set of entries has, the default first.
     *
     * @param  list<Entry>  $entries
     * @return list<string>
     */
    public static function languages(array $entries): array
    {
        $languages = [];
        foreach ($entries as $entry) {
            foreach (array_keys($entry['question']) as $language) {
                $languages[$language] = true;
            }
        }

        $languages = array_keys($languages);
        $default = ChatLanguage::default();
        usort($languages, fn (string $a, string $b) => [$a !== $default, $a] <=> [$b !== $default, $b]);

        return $languages;
    }

    /**
     * "12 questions (2 hidden) · en, uk"
     *
     * @param  list<Entry>  $entries
     */
    public static function summary(array $entries): string
    {
        $hidden = count(array_filter($entries, fn (array $entry) => ! $entry['show']));
        $count = count($entries);

        return $count.($count === 1 ? ' question' : ' questions')
            .($hidden > 0 ? " ({$hidden} hidden)" : '')
            .($count > 0 ? ' · '.implode(', ', self::languages($entries)) : '');
    }

    /**
     * The languages translation fills in when a file is loaded; none while
     * faq.translate is off.
     *
     * @return list<string>
     */
    public static function translateTo(): array
    {
        if (config('threadlive.faq.translate', false) !== true) {
            return [];
        }

        $languages = config('threadlive.faq.languages', []);

        return array_values(array_unique(array_map(
            fn ($language) => Languages::base((string) $language),
            array_filter(is_array($languages) ? $languages : [], fn ($language) => is_string($language) && $language !== ''),
        )));
    }

    /**
     * A text, or {language: text}, as {language: text}; null (with the
     * error noted) when it is neither, empty or too long.
     *
     * @param  list<string>  $errors
     * @return non-empty-array<string, string>|null
     */
    private static function texts(mixed $value, int $max, string $what, array &$errors): ?array
    {
        if (is_string($value)) {
            $value = [ChatLanguage::default() => $value];
        }

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            $errors[] = "{$what} must be a text, or {\"en\": \"…\", \"uk\": \"…\"}.";

            return null;
        }

        $texts = [];
        foreach ($value as $language => $text) {
            if (! is_string($language) || preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $language) !== 1) {
                $errors[] = "{$what}: \"{$language}\" is not a language code like en or uk.";

                return null;
            }

            $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
            if ($text === '') {
                $errors[] = "{$what} ({$language}) is empty.";

                return null;
            }

            if (mb_strlen($text) > $max) {
                $errors[] = "{$what} ({$language}) is over {$max} characters.";

                return null;
            }

            $texts[Languages::base($language)] = $text;
        }

        ksort($texts);

        return $texts;
    }

    private static function path(): ?string
    {
        $path = config('threadlive.faq.path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    private static function withoutBom(string $json): string
    {
        return str_starts_with($json, "\u{FEFF}") ? substr($json, 3) : $json;
    }
}
