<?php

declare(strict_types=1);

namespace Threadlive\Gemini;

use RuntimeException;
use Throwable;

final class GeminiException extends RuntimeException
{
    /** Gemini's HTTP status: 0 when it was not reached, or answered 200 badly. */
    public function __construct(string $message, public readonly int $status = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /** Too many requests, or the project's quota used up: try later. */
    public function isRateLimit(): bool
    {
        return $this->status === 429;
    }
}
