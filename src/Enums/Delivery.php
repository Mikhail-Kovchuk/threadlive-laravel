<?php

declare(strict_types=1);

namespace Threadlive\Enums;

/** A visitor message on its way to the Telegram topic. */
enum Delivery: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
