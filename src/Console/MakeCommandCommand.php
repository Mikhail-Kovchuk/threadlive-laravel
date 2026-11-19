<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * php artisan threadlive:make-command Invoice
 *   → app/Threadlive/Commands/InvoiceCommand.php, answering /invoice,
 *     and listed in config/threadlive.php.
 */
final class MakeCommandCommand extends Command
{
    protected $signature = 'threadlive:make-command
        {name : What it looks up, e.g. Invoice — the command becomes /invoice}';

    protected $description = 'Create a command for the managers in the support group';

    public function handle(Filesystem $files): int
    {
        $argument = $this->argument('name');
        $base = Str::studly(is_string($argument) ? $argument : '');
        $base = Str::endsWith($base, 'Command') ? Str::beforeLast($base, 'Command') : $base;
        $name = Str::snake($base);
        $class = $base.'Command';

        if ($base === '' || preg_match('/^[a-z0-9_]{1,32}$/', $name) !== 1 || $name === 'close') {
            $this->components->error("“{$name}” cannot be a Telegram command: lowercase letters, digits and _, up to 32, and not “close”.");

            return self::FAILURE;
        }

        $target = app_path("Threadlive/Commands/{$class}.php");

        if ($files->exists($target)) {
            $this->components->error("app/Threadlive/Commands/{$class}.php exists already.");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, str_replace(
            ['{{ class }}', '{{ name }}'],
            [$class, $name],
            $files->get(__DIR__.'/../../stubs/ManagerCommand.php'),
        ));
        $this->components->twoColumnDetail("app/Threadlive/Commands/{$class}.php", '<fg=green>created</>');

        $fqcn = "App\\Threadlive\\Commands\\{$class}::class";

        if ($this->register($files, $fqcn)) {
            $this->components->twoColumnDetail('config/threadlive.php', '<fg=green>lists it in commands</>');
        } else {
            $this->components->warn("Add {$fqcn} to 'commands' in config/threadlive.php (php artisan threadlive:install publishes the file).");
        }

        $this->components->info("Write what /{$name} answers in handle(), then run php artisan threadlive:setup to put it in the group's menu.");

        return self::SUCCESS;
    }

    /** Into the published config's 'commands' list, when there is one to edit. */
    private function register(Filesystem $files, string $fqcn): bool
    {
        $config = config_path('threadlive.php');

        if (! $files->exists($config)) {
            return false;
        }

        $contents = $files->get($config);
        $entry = "        {$fqcn},\n";

        $replacementEntry = str_replace('\\', '\\\\', $entry);

        $updated = preg_replace("/'commands' => \\[\\],/", "'commands' => [\n{$replacementEntry}    ],", $contents, 1, $count);
        if ($count === 0) {
            $updated = preg_replace("/('commands' => \\[\\n)/", '$1'.$replacementEntry, $contents, 1, $count);
        }
        if ($count === 0 && ! str_contains($contents, "'commands' =>")) {
            // Published before the package had commands: add the key at the end.
            $updated = preg_replace('/\\n\\];\\s*$/', "\n    'commands' => [\n{$replacementEntry}    ],\n\n];\n", $contents, 1, $count);
        }

        if ($count === 0 || ! is_string($updated)) {
            return false;
        }

        $files->put($config, $updated);

        return true;
    }
}
