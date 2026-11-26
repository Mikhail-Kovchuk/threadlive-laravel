<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Threadlive\Support\ChatLanguage;
use Threadlive\Support\Faq;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;
use Threadlive\Translation\TranslationService;

/**
 * With faq.translate on: the languages of faq.languages an entry lacks,
 * translated from its default-language text (else its first) after a file
 * is loaded. In the background — a big FAQ is hundreds of translations.
 *
 * What the file has is never replaced; an entry reworded since this started
 * keeps what it has. A translation that fails is reported and left out.
 */
final class TranslateFaq implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        /** Where /faq was sent, to say how it went: a topic, 0 = General, null = nowhere. */
        public readonly ?int $reportTo = null,
    ) {
        DeliverVisitorMessages::useConfiguredQueue($this);
    }

    public function handle(TranslationService $translation, TelegramClient $telegram): void
    {
        $targets = Faq::translateTo();

        if ($targets === [] || ! $translation->enabled()) {
            return;
        }

        /** @var array<string, array{source: string, question: string, answer: string, add: array<string, array{string, string}>}> $done */
        $done = [];
        $failed = [];

        foreach (Faq::entries() as $entry) {
            $languages = array_keys($entry['question']);
            $source = ChatLanguage::pick($languages, []);
            $missing = array_diff($targets, $languages);

            foreach ($missing as $language) {
                $question = $translation->translate($entry['question'][$source], $language, $source);
                $answer = $translation->translate($entry['answer'][$source], $language, $source);

                if ($question === null || $answer === null) {
                    $failed[] = "{$entry['id']} → {$language}";

                    continue;
                }

                $done[$entry['id']] ??= [
                    'source' => $source,
                    'question' => $entry['question'][$source],
                    'answer' => $entry['answer'][$source],
                    'add' => [],
                ];
                $done[$entry['id']]['add'][$language] = [$question->text, $answer->text];
            }
        }

        // Into the FAQ as it is now: a file loaded meanwhile wins.
        $added = 0;
        $entries = Faq::entries();
        foreach ($entries as $i => $entry) {
            $translated = $done[$entry['id']] ?? null;
            $source = $translated['source'] ?? null;

            if ($translated === null || ($entry['question'][$source] ?? null) !== $translated['question'] || ($entry['answer'][$source] ?? null) !== $translated['answer']) {
                continue;
            }

            foreach ($translated['add'] as $language => [$question, $answer]) {
                if (! isset($entry['question'][$language])) {
                    $entries[$i]['question'][$language] = $question;
                    $entries[$i]['answer'][$language] = $answer;
                    $added++;
                }
            }

            ksort($entries[$i]['question']);
            ksort($entries[$i]['answer']);
        }

        if ($added > 0) {
            Faq::save($entries);
        }

        $this->report($telegram, $added, $failed);
    }

    /**
     * @param  list<string>  $failed
     */
    private function report(TelegramClient $telegram, int $added, array $failed): void
    {
        if ($this->reportTo === null) {
            return;
        }

        $text = '🌐 FAQ translated: '.$added.' '.($added === 1 ? 'answer' : 'answers').' added.';
        if ($failed !== []) {
            $text .= "\nNot translated (try loading the file again later): ".e(implode(', ', array_slice($failed, 0, 20)))
                .(count($failed) > 20 ? ' …' : '');
        }

        try {
            $telegram->sendMessage((string) config('threadlive.chat_id'), $text, $this->reportTo === 0 ? null : $this->reportTo, true);
        } catch (TelegramException $e) {
            report($e);
        }
    }
}
