<?php

declare(strict_types=1);

namespace Threadlive\Tests\Fixtures;

use Threadlive\CommandReply;
use Threadlive\Contracts\ManagerCommand;

/** A manager command as an application would write one: one record, A-1. */
final class LookupCommand implements ManagerCommand
{
    public function name(): string
    {
        return 'lookup';
    }

    public function description(): string
    {
        return 'Look up a record by its code';
    }

    public function handle(string $argument): ?CommandReply
    {
        return $argument === 'A-1'
            ? CommandReply::make('Record A-1')
                ->line('Status', 'Active')
                ->line('Owner', 'anna@example.com')
                ->line('Note', null)
                ->link('https://admin.example.com/records/1')
            : null;
    }
}
