<?php

declare(strict_types=1);

namespace Threadlive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Threadlive\Models\Conversation;

/** Closed by a manager (/close, the Close button) or by the schedule. */
final class ConversationClosed
{
    use Dispatchable;

    public function __construct(public readonly Conversation $conversation) {}
}
