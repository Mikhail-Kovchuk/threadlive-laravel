<?php

declare(strict_types=1);

namespace Threadlive\Tests\Fixtures;

use Illuminate\Http\Request;
use Threadlive\ChatUser;
use Threadlive\Contracts\ChatHost;

/** A host the tests steer: who is signed in, and what the context line says. */
final class FakeChatHost implements ChatHost
{
    public static ?ChatUser $user = null;

    /** @var (callable(array<string, mixed>): ?string)|null */
    public static $contextLine = null;

    /** @var array<string, string> */
    public static array $commands = [];

    /** @var (callable(string, string): ?array<string, mixed>)|null */
    public static $runCommand = null;

    public static ?string $adminUrl = null;

    public static function reset(): void
    {
        self::$user = null;
        self::$contextLine = null;
        self::$commands = [];
        self::$runCommand = null;
        self::$adminUrl = null;
    }

    public function commands(): array
    {
        return self::$commands;
    }

    public function runCommand(string $command, string $argument): ?array
    {
        /** @var array{title: string, lines?: list<array{label: string, value: string, url?: string}>, url?: string}|null */
        return self::$runCommand === null ? null : (self::$runCommand)($command, $argument);
    }

    public function user(Request $request): ?ChatUser
    {
        return self::$user;
    }

    public function cardLines(Request $request, ?ChatUser $user, array $client): array
    {
        return [];
    }

    public function adminUrl(?ChatUser $user): ?string
    {
        return $user === null ? null : self::$adminUrl;
    }

    public function contextLine(Request $request, ?ChatUser $user, array $client): ?string
    {
        return self::$contextLine === null ? null : (self::$contextLine)($client);
    }
}
