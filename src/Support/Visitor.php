<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Http\Request;
use Threadlive\ChatUser;
use Threadlive\Contracts\ChatHost;
use Threadlive\Models\Conversation;

/** Who is asking, and their conversation if they have one. */
final class Visitor
{
    public function __construct(
        public readonly ?ChatUser $user,
        public readonly ?string $tokenHash,
        public ?Conversation $conversation,
    ) {}

    /**
     * A signed-in user is known by the host; a guest by the token their first
     * message returned (X-Threadlive-Token), of which only the hash is stored.
     *
     * A request with both attaches the guest's conversation to the user, when
     * the user has none yet. A guest token never opens a conversation that
     * belongs to a user: after signing out on a shared computer, the next
     * guest must not see it.
     */
    public static function resolve(Request $request, ChatHost $host): self
    {
        $user = $host->user($request);
        $token = $request->header('X-Threadlive-Token');
        $tokenHash = is_string($token) && $token !== '' ? hash('sha256', $token) : null;

        $guestConversation = $tokenHash === null ? null : Conversation::query()
            ->where('visitor_token_hash', $tokenHash)
            ->whereNull('user_ref')
            ->first();

        if ($user === null) {
            return new self(null, $tokenHash, $guestConversation);
        }

        $conversation = Conversation::query()->where('user_ref', $user->id)->latest('id')->first();

        if ($conversation === null && $guestConversation !== null) {
            $guestConversation->forceFill([
                'user_ref' => $user->id,
                'name' => $guestConversation->name ?? $user->name,
                'email' => $guestConversation->email ?? $user->email,
            ])->save();
            $conversation = $guestConversation;
        }

        return new self($user, $tokenHash, $conversation);
    }

    public function canWrite(): bool
    {
        return $this->user !== null || (bool) config('threadlive.allow_guests', true);
    }
}
