<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Threadlive\Contracts\ManagerCommand;

/** The application's manager commands, from config('threadlive.commands'). */
final class Commands
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, ManagerCommand> by name
     *
     * @throws InvalidArgumentException naming the bad entry
     */
    public function all(): array
    {
        $classes = config('threadlive.commands', []);
        $commands = [];

        foreach (is_array($classes) ? $classes : [] as $class) {
            $command = is_string($class) && class_exists($class) ? $this->container->make($class) : null;

            if (! $command instanceof ManagerCommand) {
                throw new InvalidArgumentException(
                    'threadlive.commands: '.var_export($class, true).' is not a class implementing '.ManagerCommand::class.'.'
                );
            }

            $name = $command->name();

            if (preg_match('/^[a-z0-9_]{1,32}$/', $name) !== 1 || $name === 'close') {
                throw new InvalidArgumentException(
                    "threadlive.commands: {$class} is named “{$name}”; use lowercase letters, digits and _, up to 32, and not “close”."
                );
            }

            if (isset($commands[$name])) {
                throw new InvalidArgumentException("threadlive.commands: two commands are named “{$name}”.");
            }

            $commands[$name] = $command;
        }

        return $commands;
    }
}
