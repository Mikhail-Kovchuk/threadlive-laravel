<?php

declare(strict_types=1);

namespace Threadlive\Host;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Threadlive\ChatUser;
use Threadlive\Contracts\ChatHost;

/**
 * A working start for any Laravel app: the user from the configured guard
 * (`threadlive.guard`, null = the default one), and nothing else.
 *
 * The guard is asked directly, without auth middleware on the routes, so a
 * guest is simply a request it does not recognise. Token guards (Sanctum,
 * JWT) read the request's Authorization header themselves.
 */
class GuardChatHost implements ChatHost
{
    public function __construct(protected AuthFactory $auth) {}

    public function user(Request $request): ?ChatUser
    {
        $guard = config('threadlive.guard');
        $user = $this->auth->guard(is_string($guard) && $guard !== '' ? $guard : null)->user();

        if ($user === null) {
            return null;
        }

        $name = $user->name ?? null;
        $email = $user->email ?? null;

        return new ChatUser(
            id: (string) $user->getAuthIdentifier(),
            name: is_string($name) ? $name : null,
            email: is_string($email) ? $email : null,
        );
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
        return null;
    }

    public function commands(): array
    {
        return [];
    }

    public function runCommand(string $command, string $argument): ?array
    {
        return null;
    }
}
