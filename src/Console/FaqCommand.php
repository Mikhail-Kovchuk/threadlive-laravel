<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Threadlive\Jobs\TranslateFaq;
use Threadlive\Support\Faq;
use Threadlive\Translation\TranslationService;

/**
 * The FAQ from the server, as /faq does it in the group: export prints (or
 * writes) the current faq.json, import puts a file in its place — checked
 * whole first.
 */
final class FaqCommand extends Command
{
    protected $signature = 'threadlive:faq
        {action : import or export}
        {file? : the faq.json to import, or where to export (default: print it)}';

    protected $description = 'Load the widget\'s quick answers from a faq.json, or write out the current one';

    public function handle(TranslationService $translation): int
    {
        $action = $this->argument('action');
        $file = $this->argument('file');
        $file = is_string($file) && $file !== '' ? $file : null;

        if ($action === 'export') {
            $json = Faq::encode();

            if ($file === null) {
                $this->output->write($json);
            } else {
                file_put_contents($file, $json);
                $this->components->info('Written to '.$file.': '.Faq::summary(Faq::entries()).'.');
            }

            return self::SUCCESS;
        }

        if ($action !== 'import' || $file === null) {
            $this->components->error('Use: php artisan threadlive:faq import faq.json, or threadlive:faq export [faq.json].');

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->components->error("No file {$file}.");

            return self::FAILURE;
        }

        [$entries, $errors] = Faq::parse((string) file_get_contents($file));

        if ($errors !== []) {
            $this->components->error('The FAQ was not changed:');
            $this->components->bulletList($errors);

            return self::FAILURE;
        }

        Faq::save($entries);
        $this->components->info('FAQ updated: '.Faq::summary($entries).'.');

        if (Faq::translateTo() !== [] && $translation->enabled()) {
            $this->components->task('Translating what is missing ('.implode(', ', Faq::translateTo()).')', function () {
                TranslateFaq::dispatchSync(null);
            });
            $this->components->info('Now: '.Faq::summary(Faq::entries()).'.');
        }

        return self::SUCCESS;
    }
}
