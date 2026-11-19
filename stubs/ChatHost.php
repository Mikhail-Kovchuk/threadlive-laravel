<?php

namespace App\Threadlive;

use Illuminate\Http\Request;
use Threadlive\ChatUser;
use Threadlive\Host\GuardChatHost;

/**
 * Tells Threadlive about this application. Published by threadlive:install
 * and named by config('threadlive.host'); it is yours now — package updates
 * never touch it.
 *
 * The parent reads the signed-in user from config('threadlive.guard') (null:
 * the default guard) and adds nothing else. Change what you need below.
 *
 * Commands for your managers (/something in the support group) are classes
 * of their own: php artisan threadlive:make-command Name.
 */
class ChatHost extends GuardChatHost
{
    /**
     * Lines on the visitor card in Telegram, for the managers. Plain text;
     * the package escapes it.
     *
     * $client is what the widget sent: url, title, and `attributes` — what
     * the page's getContext() returned.
     */
    public function cardLines(Request $request, ?ChatUser $user, array $client): array
    {
        // e.g. [['label' => 'Plan', 'value' => 'Pro', 'url' => url("/admin/users/{$user?->id}/billing")]]
        return parent::cardLines($request, $user, $client);
    }

    /** A link to this user in your admin panel, shown on the visitor card. */
    public function adminUrl(?ChatUser $user): ?string
    {
        // e.g. $user ? url("/admin/users/{$user->id}") : null
        return parent::adminUrl($user);
    }

    /**
     * What the VISITOR sees the conversation is about, as a card above their
     * message — from what the page sent. Null for none. Public codes only,
     * never an internal id: the visitor sees it.
     */
    public function contextLine(Request $request, ?ChatUser $user, array $client): ?string
    {
        // e.g. the page passes getContext={() => ({ invoice: 'INV-1042' })}:
        //   isset($client['attributes']['invoice']) ? 'About invoice '.$client['attributes']['invoice'] : null
        return parent::contextLine($request, $user, $client);
    }
}
