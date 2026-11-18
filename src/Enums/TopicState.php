<?php

declare(strict_types=1);

namespace Threadlive\Enums;

/** The conversation's forum topic in Telegram — a disposable view of it. */
enum TopicState: string
{
    case None = 'none';
    case Open = 'open';
    case Closed = 'closed';
    case Deleted = 'deleted';
}
