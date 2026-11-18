<?php

declare(strict_types=1);

namespace Threadlive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Threadlive\Models\Conversation;

/** A visitor's first message created their conversation. */
final class ConversationStarted
{
    use Dispatchable;

    public function __construct(public readonly Conversation $conversation) {}
}
