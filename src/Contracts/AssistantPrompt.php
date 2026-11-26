<?php

declare(strict_types=1);

namespace Threadlive\Contracts;

use Threadlive\Assistant\PromptContext;

/**
 * Everything the AI assistant is told before it reads a conversation. The
 * package's own is Threadlive\Assistant\DefaultPrompt — a general one for a
 * website's live chat. An application replaces it whole with its own class
 * (php artisan threadlive:make-prompt → app/Threadlive/AssistantPrompt.php),
 * named in config('threadlive.assistant.prompt').
 *
 * Whatever it says, the answer's shape is fixed by the package (answer or
 * handoff, the reply, the summary for the managers): a rewritten prompt
 * cannot break handing a conversation to a manager.
 */
interface AssistantPrompt
{
    public function instructions(PromptContext $context): string;
}
