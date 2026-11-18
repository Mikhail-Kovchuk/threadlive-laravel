<?php

declare(strict_types=1);

namespace Threadlive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;

/** A manager's reply in the topic was stored for the visitor. */
final class AgentReplied
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
    ) {}
}
