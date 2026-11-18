<?php

declare(strict_types=1);

namespace Threadlive;

/** A signed-in visitor, as the host describes them. */
final readonly class ChatUser
{
    public function __construct(
        /** The host's user id, as a string: hosts use ints, UUIDs, … */
        public string $id,
        public ?string $name = null,
        public ?string $email = null,
    ) {}
}
