<?php

declare(strict_types=1);

namespace Threadlive\Assistant;

use InvalidArgumentException;
use Threadlive\Translation\Languages;

/** What the assistant decided: an answer, or the conversation for the managers. */
final readonly class AssistantAnswer
{
    public function __construct(
        /** True: a manager takes over; `reply` tells the visitor so. */
        public bool $handoff,
        /** To the visitor, in their language. */
        public string $reply,
        /** The visitor's language, two letters; '' when unknown. */
        public string $language,
        /** The reply in the managers' language; '' when it is in it already. */
        public string $replyForManagers = '',
        /** Why a manager is needed, for the managers. */
        public string $reason = '',
        /** What the visitor needs, in a sentence or two, for the managers. */
        public string $summary = '',
    ) {}

    /**
     * From the JSON a model returned: {action, reply, language,
     * reply_for_managers, handoff_reason, summary}.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException when it is not that
     */
    public static function fromArray(array $data): self
    {
        $action = $data['action'] ?? null;
        $reply = is_string($data['reply'] ?? null) ? trim($data['reply']) : '';

        if (! in_array($action, ['answer', 'handoff'], true) || ($action === 'answer' && $reply === '')) {
            throw new InvalidArgumentException('The assistant gave no usable answer.');
        }

        $text = fn (string $key): string => is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        $language = $text('language');

        return new self(
            handoff: $action === 'handoff',
            reply: $reply,
            language: preg_match('/^[a-z]{2}/i', $language) === 1 ? Languages::base($language) : '',
            replyForManagers: $text('reply_for_managers'),
            reason: $text('handoff_reason'),
            summary: $text('summary'),
        );
    }
}
