<?php

declare(strict_types=1);

namespace Threadlive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;

/** A visitor message was stored; `$context` is the card stored before it, if any. */
final class VisitorMessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly ?Message $context = null,
    ) {}
}
