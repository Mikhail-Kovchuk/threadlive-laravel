<?php

declare(strict_types=1);

namespace Threadlive\Contracts;

use Illuminate\Http\Request;
use Threadlive\ChatUser;

/**
 * Everything the package needs to know about the application it runs in.
 *
 * threadlive:install publishes a starting implementation into the app
 * (app/Threadlive/ChatHost.php) and config('threadlive.host') names it.
 * From then on it is the application's code; package updates never change it.
 */
interface ChatHost
{
    /** The signed-in user of this request, or null for a guest. */
    public function user(Request $request): ?ChatUser;

    /**
     * Extra lines for the visitor card posted in the topic, for the managers,
     * e.g. "Plan: Pro", "Invoice INV-1042 · unpaid". Plain text;
     * the package escapes it.
     *
     * @param  array{url?: string, title?: string, attributes?: array<string, string>}  $client  what the widget sent
     * @return list<array{label: string, value: string, url?: string}>
     */
    public function cardLines(Request $request, ?ChatUser $user, array $client): array;

    /** A link to this user in the application's admin panel, or null. */
    public function adminUrl(?ChatUser $user): ?string;

    /**
     * One line the VISITOR sees about what the conversation is about from
     * here on, e.g. "About invoice INV-1042", or null.
     * Plain text. Codes only — never an internal id.
     *
     * @param  array{url?: string, title?: string, attributes?: array<string, string>}  $client  what the widget sent
     */
    public function contextLine(Request $request, ?ChatUser $user, array $client): ?string;
}
