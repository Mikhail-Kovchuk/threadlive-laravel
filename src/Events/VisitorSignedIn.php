<?php

declare(strict_types=1);

namespace Threadlive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Threadlive\ChatUser;
use Threadlive\Models\Conversation;

/** A guest signed in mid-conversation; the conversation is now the user's. */
final class VisitorSignedIn
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ChatUser $user,
    ) {}
}
