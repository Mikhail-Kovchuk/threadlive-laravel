<?php

declare(strict_types=1);

namespace Threadlive\Contracts;

use Threadlive\CommandReply;

/**
 * A command for the managers in the support group: "/{name} {argument}" in
 * any topic or in General, listed in the group's "/" menu.
 *
 * Each is a class of the application, listed in config('threadlive.commands')
 * (php artisan threadlive:make-command Name creates and lists one). The
 * package knows nothing about what it looks up. The answer goes to the
 * managers only — never to a visitor.
 */
interface ManagerCommand
{
    /** "/{name}": lowercase letters, digits and _, up to 32; not "close". */
    public function name(): string;

    /** Shown in the group's menu, and when the bot asks for the argument. */
    public function description(): string;

    /** The answer to "/{name} {argument}", or null when nothing matches. */
    public function handle(string $argument): ?CommandReply;
}
