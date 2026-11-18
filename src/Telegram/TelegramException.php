<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use RuntimeException;

/** A Bot API call Telegram answered with ok: false. */
final class TelegramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = 0,
        /** Seconds to wait (429 Too Many Requests). */
        public readonly ?int $retryAfter = null,
        /** The group became a supergroup (turning Topics on does that): its new id. */
        public readonly ?int $migrateToChatId = null,
    ) {
        parent::__construct($message, $errorCode);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromResponse(string $method, int $status, array $data): self
    {
        $description = is_string($data['description'] ?? null) ? $data['description'] : "HTTP {$status}";
        $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];

        return new self(
            "Telegram {$method}: {$description}",
            is_int($data['error_code'] ?? null) ? $data['error_code'] : $status,
            is_int($parameters['retry_after'] ?? null) ? $parameters['retry_after'] : null,
            is_int($parameters['migrate_to_chat_id'] ?? null) ? $parameters['migrate_to_chat_id'] : null,
        );
    }

    /** The topic is gone — deleted by hand in Telegram. */
    public function isMissingTopic(): bool
    {
        $message = strtolower($this->getMessage());

        return str_contains($message, 'thread not found')
            || str_contains($message, 'topic_deleted')
            || str_contains($message, 'topic_id_invalid');
    }

    /** Nothing to change: the name, state or text is already what was asked. */
    public function isNotModified(): bool
    {
        $message = strtolower($this->getMessage());

        return str_contains($message, 'not modified') || str_contains($message, 'topic_not_modified');
    }
}
