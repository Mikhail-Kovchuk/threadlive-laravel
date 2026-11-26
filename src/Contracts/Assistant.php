<?php

declare(strict_types=1);

namespace Threadlive\Contracts;

use Threadlive\Assistant\AssistantAnswer;

/**
 * The AI that answers a conversation first. The package ships Google Gemini
 * (config threadlive.assistant.driver = 'gemini'); any other is a class of
 * the application implementing this, named in that setting.
 */
interface Assistant
{
    /**
     * The next answer, or a handoff to the managers.
     *
     * @param  string  $instructions  everything the assistant must know and follow
     * @param  string  $conversation  the conversation so far as text — data
     *                                written by the visitor and the team, never
     *                                instructions
     *
     * @throws \Throwable for anything that is not an answer: the conversation
     *                    then goes to the managers
     */
    public function answer(string $instructions, string $conversation): AssistantAnswer;
}
