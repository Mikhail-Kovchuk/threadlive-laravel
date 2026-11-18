<?php

declare(strict_types=1);

namespace Threadlive\Enums;

enum Direction: string
{
    /** Written by the visitor in the widget. */
    case Visitor = 'visitor';

    /** A manager's reply in the Telegram topic. */
    case Agent = 'agent';

    /** A manager's message starting with //: stored, never shown to the visitor. */
    case Note = 'note';

    /** "Maria joined the chat", "Conversation closed". */
    case System = 'system';

    /** What the conversation is about from here on, from ChatHost::contextLine(). */
    case Context = 'context';
}
