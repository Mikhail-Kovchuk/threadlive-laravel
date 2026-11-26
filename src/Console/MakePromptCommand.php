<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * php artisan threadlive:make-prompt
 *   → app/Threadlive/AssistantPrompt.php (or the class config
 *     threadlive.assistant.prompt names): the package's prompt, as the
 *     application's own to rewrite. It is used as soon as it exists.
 */
final class MakePromptCommand extends Command
{
    protected $signature = 'threadlive:make-prompt';

    protected $description = 'Create the AI assistant\'s prompt in your application, from the package\'s one';

    public function handle(Filesystem $files): int
    {
        $class = config('threadlive.assistant.prompt');
        $class = is_string($class) && $class !== '' ? ltrim($class, '\\') : 'App\\Threadlive\\AssistantPrompt';
        $root = app()->getNamespace();

        if (! str_starts_with($class, $root)) {
            $this->components->error("threadlive.assistant.prompt is {$class}: name a class under {$root} (e.g. {$root}Threadlive\\AssistantPrompt) to create it here.");

            return self::FAILURE;
        }

        $relative = str_replace('\\', '/', Str::after($class, $root)).'.php';
        $target = app_path($relative);

        if ($files->exists($target)) {
            $this->components->error("app/{$relative} exists already.");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, self::source($class));

        $this->components->twoColumnDetail("app/{$relative}", '<fg=green>created</>');
        $this->components->info('It is the prompt now. Rewrite it as you need; php artisan threadlive:assistant show prints '
            .'what the assistant gets, threadlive:assistant ask "…" tries it.');

        return self::SUCCESS;
    }

    /** The package's DefaultPrompt, as the class $class. */
    public static function source(string $class): string
    {
        $namespace = Str::beforeLast($class, '\\');
        $name = class_basename($class);
        $source = (string) file_get_contents(__DIR__.'/../Assistant/DefaultPrompt.php');

        $source = str_replace(
            ['namespace Threadlive\\Assistant;', 'use Threadlive\\Contracts\\AssistantPrompt as Prompt;'],
            ["namespace {$namespace};", "use Threadlive\\Assistant\\PromptContext;\nuse Threadlive\\Contracts\\AssistantPrompt as Prompt;"],
            $source,
        );

        $doc = <<<'DOC'
            /**
             * The AI assistant's prompt: everything it is told before it reads a
             * conversation. Rewrite it whole as you need — the answer's shape (answer
             * or handoff, the reply, the summary for the managers) is fixed by the
             * package. $c has the facts: who we are (config assistant.about), now,
             * the working hours, the FAQ, the page, the languages.
             */
            DOC;

        return (string) preg_replace('/\/\*\*.*?\*\/\s*final class DefaultPrompt/s', $doc."\nfinal class {$name}", $source, 1);
    }
}
