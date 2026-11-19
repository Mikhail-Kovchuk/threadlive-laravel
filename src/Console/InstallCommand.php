<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Threadlive\Support\ConfigPublisher;

/**
 * Everything after `composer require`, in one command: the config, the
 * migration, the ChatHost class, the .env keys — and a word about what will
 * not work yet.
 *
 * Nothing already there is overwritten without --force (the migration never:
 * a second copy would fail `migrate`). What is published is the
 * application's code from then on.
 */
final class InstallCommand extends Command
{
    protected $signature = 'threadlive:install
        {--force : Overwrite app/Threadlive/ChatHost.php, and start config/threadlive.php over (keeping a .bak)}
        {--no-migrate : Do not offer to run the migration}';

    protected $description = 'Publish the Threadlive config, migration and ChatHost class, and add the .env keys';

    private const MIGRATION = 'create_threadlive_tables';

    public function handle(Filesystem $files, ConfigPublisher $config): int
    {
        $this->components->info('Installing threadlive/threadlive-laravel');

        // The host class first: the config is pointed at it once it exists.
        $this->publishHost($files);
        ConfigCommand::report($this, $config->sync((bool) $this->option('force')));
        $this->publishMigration($files);
        $this->addEnvKeys($files);

        $warnings = $this->warnings();

        if (! $this->option('no-migrate') && $this->confirm('Run the migration now?', true)) {
            $this->call('migrate');
        }

        $this->newLine();
        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info('Installed. Next:');
        $this->components->bulletList([
            'Create a bot with @BotFather and put its token in THREADLIVE_BOT_TOKEN — a new bot, not one this app already uses.',
            'Create a Telegram group, turn Topics on, add the bot as an admin with "Manage topics", "Delete messages" and "Pin messages", add your team.',
            'Put the group id (-100…) in THREADLIVE_GROUP_ID, then run php artisan threadlive:setup.',
            'config/threadlive.php: hours, timings, topics, commands — change them there, never in vendor/. '
                .'After a package update, php artisan threadlive:config adds the new settings.',
            'app/Threadlive/ChatHost.php: who is signed in, card lines for managers, context cards for visitors.',
            'Run a queue worker and the scheduler (php artisan schedule:run every minute).',
            'Set THREADLIVE_ENABLED=true when setup passes.',
        ]);

        return self::SUCCESS;
    }

    /**
     * Checked by name, not left to vendor:publish, which would add a second
     * copy with a new date on every run.
     */
    private function publishMigration(Filesystem $files): void
    {
        $existing = $files->glob(database_path('migrations/*_'.self::MIGRATION.'.php'));

        if ($existing !== []) {
            $this->components->twoColumnDetail('database/migrations/'.basename($existing[0]), '<fg=yellow>exists, kept</>');

            return;
        }

        $name = date('Y_m_d_His').'_'.self::MIGRATION.'.php';
        $files->ensureDirectoryExists(database_path('migrations'));
        $files->copy(__DIR__.'/../../database/migrations/2026_11_18_000000_'.self::MIGRATION.'.php', database_path('migrations/'.$name));
        $this->components->twoColumnDetail('database/migrations/'.$name, '<fg=green>published</>');
    }

    private function publishHost(Filesystem $files): void
    {
        $target = app_path('Threadlive/ChatHost.php');

        if ($files->exists($target) && ! $this->option('force')) {
            $this->components->twoColumnDetail('app/Threadlive/ChatHost.php', '<fg=yellow>exists, kept</>');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy(__DIR__.'/../../stubs/ChatHost.php', $target);
        $this->components->twoColumnDetail('app/Threadlive/ChatHost.php', '<fg=green>published</>');
    }

    /**
     * Adds what is missing. The webhook secret is generated into .env (it is
     * only ever compared with what Telegram sends back); everything else is
     * left empty — a made-up token would look configured and fail later.
     */
    private function addEnvKeys(Filesystem $files): void
    {
        foreach (['.env' => true, '.env.example' => false] as $name => $withSecret) {
            $path = base_path($name);

            if (! $files->exists($path)) {
                continue;
            }

            $contents = $files->get($path);
            $keys = [
                'THREADLIVE_ENABLED' => 'false',
                'THREADLIVE_BOT_TOKEN' => '',
                'THREADLIVE_GROUP_ID' => '',
                'THREADLIVE_WEBHOOK_SECRET' => $withSecret ? Str::random(48) : '',
            ];
            $missing = array_filter(
                $keys,
                fn (string $key) => preg_match('/^'.$key.'=/m', $contents) !== 1,
                ARRAY_FILTER_USE_KEY,
            );

            if ($missing === []) {
                $this->components->twoColumnDetail($name, '<fg=yellow>already has THREADLIVE_*</>');

                continue;
            }

            $lines = implode("\n", array_map(fn (string $key, string $value) => "{$key}={$value}", array_keys($missing), $missing));
            $files->append($path, (str_ends_with($contents, "\n") || $contents === '' ? '' : "\n")."\n".$lines."\n");
            $this->components->twoColumnDetail($name, '<fg=green>added '.implode(', ', array_keys($missing)).'</>');
        }
    }

    /**
     * @return list<string>
     */
    private function warnings(): array
    {
        $warnings = [];
        $prefix = config('threadlive.route_prefix', 'threadlive');
        $prefix = is_string($prefix) ? trim($prefix, '/') : 'threadlive';

        $paths = config('cors.paths', ['api/*', 'sanctum/csrf-cookie']);
        $covered = collect(is_array($paths) ? $paths : [])
            ->contains(fn ($pattern) => is_string($pattern) && Str::is($pattern, $prefix.'/bootstrap'));

        if (! $covered) {
            $warnings[] = "CORS does not cover {$prefix}/*. If your website calls this API from another origin, "
                ."add '{$prefix}/*' to `paths` in config/cors.php (php artisan config:publish cors creates it), "
                .'or the browser blocks the widget.';
        }

        if (config('queue.default') === 'sync') {
            $warnings[] = 'QUEUE_CONNECTION is sync: messages would be sent to Telegram inside the visitor\'s request. '
                .'Use a real queue (database is fine) and run php artisan queue:work.';
        }

        return $warnings;
    }
}
