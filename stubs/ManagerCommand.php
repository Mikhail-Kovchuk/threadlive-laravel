<?php

namespace App\Threadlive\Commands;

use Threadlive\CommandReply;
use Threadlive\Contracts\ManagerCommand;

/**
 * "/{{ name }} <argument>" in the support group — for your managers only;
 * the answer never reaches a visitor.
 *
 * Listed in config('threadlive.commands'); after changing the name or the
 * description, run php artisan threadlive:setup to update the group's menu.
 */
class {{ class }} implements ManagerCommand
{
    public function name(): string
    {
        return '{{ name }}';
    }

    public function description(): string
    {
        return 'Look up a record by its code';
    }

    public function handle(string $argument): ?CommandReply
    {
        // $record = Record::firstWhere('code', $argument);
        //
        // if ($record === null) {
        //     return null; // "Nothing found for …"
        // }
        //
        // return CommandReply::make("Record {$record->code}")
        //     ->line('Status', $record->status)
        //     ->line('Owner', $record->owner->email, url("/admin/users/{$record->owner_id}"))
        //     ->link(url("/admin/records/{$record->id}"));

        return null;
    }
}
