<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Threadlive\Support\ConfigPublisher;

/**
 * config/threadlive.php in the application — where settings are changed,
 * never in vendor/. Run it after installing; run it again after a package
 * update to get the settings it brought.
 */
final class ConfigCommand extends Command
{
    protected $signature = 'threadlive:config
        {--force : Start over from the package defaults (the current file is kept as config/threadlive.php.bak)}';

    protected $description = 'Create config/threadlive.php, or add the settings a package update brought';

    public function handle(ConfigPublisher $publisher): int
    {
        $result = $publisher->sync((bool) $this->option('force'));

        return self::report($this, $result) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Shared with threadlive:install.
     *
     * @param  array{status: string, added: list<string>, unknown: list<string>, backup: string|null, error: string|null}  $result
     */
    public static function report(Command $command, array $result): bool
    {
        $components = $command->components;

        match ($result['status']) {
            'published' => $components->twoColumnDetail('config/threadlive.php', '<fg=green>published</>'),
            'updated' => $components->twoColumnDetail('config/threadlive.php', '<fg=green>added '.implode(', ', $result['added']).'</>'),
            'current' => $components->twoColumnDetail('config/threadlive.php', '<fg=yellow>up to date, kept</>'),
            default => $components->error((string) $result['error']),
        };

        if ($result['backup'] !== null) {
            $components->twoColumnDetail('config/threadlive.php.bak', 'the previous file');
        }

        if ($result['unknown'] !== []) {
            $components->warn('Not settings of this package version (safe to remove): '.implode(', ', $result['unknown']).'.');
        }

        return $result['status'] !== 'failed';
    }
}
