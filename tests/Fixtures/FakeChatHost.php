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

    public static function reset(): void
    {
        self::$user = null;
        self::$contextLine = null;
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
        return null;
    }

    public function contextLine(Request $request, ?ChatUser $user, array $client): ?string
    {
        return self::$contextLine === null ? null : (self::$contextLine)($client);
    }
}
