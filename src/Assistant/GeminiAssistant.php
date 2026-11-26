<?php

declare(strict_types=1);

namespace Threadlive\Assistant;

use Threadlive\Contracts\Assistant;
use Threadlive\Gemini\GeminiClient;

/**
 * The assistant on Google Gemini: one call per answer, JSON by schema. The
 * schema's descriptions say what each field is, so the answer keeps its
 * shape whatever the prompt says.
 */
final class GeminiAssistant implements Assistant
{
    public function __construct(
        private readonly GeminiClient $gemini,
        /** "English": what the managers read. */
        private readonly string $managersLanguage = 'English',
    ) {}

    public function answer(string $instructions, string $conversation): AssistantAnswer
    {
        return AssistantAnswer::fromArray($this->gemini->json($instructions, $conversation, $this->schema(), timeout: 30));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $managers = $this->managersLanguage;

        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['answer', 'handoff'],
                    'description' => '"answer": you answer the visitor. "handoff": a human manager takes over the conversation.',
                ],
                'reply' => [
                    'type' => 'string',
                    'description' => 'What the visitor reads next, plain text. On a handoff: that a manager will continue in this chat.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'ISO 639-1 code of the language the visitor writes in, lowercase.',
                ],
                'reply_for_managers' => [
                    'type' => 'string',
                    'description' => "The reply itself translated into {$managers}, word for word — not a description of it; "
                        ."\"\" if the reply is in {$managers} already.",
                ],
                'handoff_reason' => [
                    'type' => 'string',
                    'description' => "On a handoff: why a manager is needed, a few words in {$managers}. \"\" when answering.",
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => "On a handoff: what the visitor wants, one or two sentences in {$managers}. \"\" when answering.",
                ],
            ],
            'required' => ['action', 'reply', 'language', 'reply_for_managers', 'handoff_reason', 'summary'],
        ];
    }
}
