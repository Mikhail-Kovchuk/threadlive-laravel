<?php

declare(strict_types=1);

namespace Threadlive\Enums;

enum ConversationStatus: string
{
    /** The visitor wrote last; nobody has answered yet. */
    case Waiting = 'waiting';

    /** An agent wrote last. */
    case Answered = 'answered';

    case Closed = 'closed';
}
