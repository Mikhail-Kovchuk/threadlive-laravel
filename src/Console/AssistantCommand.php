<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Threadlive\Assistant\AssistantService;
use Threadlive\Models\Conversation;
use Throwable;

/**
 * Trying the assistant from the terminal, no widget or Telegram:
 *   threadlive:assistant show        — the prompt as the AI gets it now
 *   threadlive:assistant ask "…"     — one answer to that question
 * --conversation=ID: for a real conversation (its page, language, and for
 * ask its messages before the question).
 */
final class AssistantCommand extends Command
{
    protected $signature = 'threadlive:assistant
        {action : show or ask}
        {question? : what the visitor asks (for ask)}
        {--conversation= : a conversation id to use}';

    protected $description = 'Show the AI assistant\'s prompt, or ask it a question';

    public function handle(AssistantService $assistant): int
    {
        $action = $this->argument('action');
        $question = $this->argument('question');
        $question = is_string($question) ? trim($question) : '';

        $id = $this->option('conversation');
        $conversation = is_string($id) && $id !== '' ? Conversation::query()->find((int) $id) : new Conversation;

        if ($conversation === null) {
            $this->components->error("No conversation {$id}.");

            return self::FAILURE;
        }

        if ($action === 'show') {
            $this->line('<fg=gray>Prompt: '.$assistant->prompt()::class.'</>');
            $this->newLine();
            $this->line($assistant->instructions($conversation));

            return self::SUCCESS;
        }

        if ($action !== 'ask' || $question === '') {
            $this->components->error('Use: php artisan threadlive:assistant show, or threadlive:assistant ask "a question".');

            return self::FAILURE;
        }

        $driver = $assistant->driver();
        if ($driver === null) {
            $this->components->error('No assistant: set THREADLIVE_GEMINI_KEY (or assistant.driver to your own class).');

            return self::FAILURE;
        }

        $before = $conversation->exists ? $assistant->transcript($conversation)."\n" : '';
        $text = AssistantService::CONVERSATION_HEADER.$before.'[visitor] '.$question;

        $started = microtime(true);
        try {
            $answer = $driver->answer($assistant->instructions($conversation), $text);
        } catch (Throwable $e) {
            $this->components->error('The assistant failed — a real conversation would go to the managers: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Action', $answer->handoff ? '<fg=yellow>handoff</>' : '<fg=green>answer</>');
        $this->components->twoColumnDetail('Language', $answer->language !== '' ? $answer->language : '—');
        $this->components->twoColumnDetail('Took', round(microtime(true) - $started, 1).' s');
        $this->newLine();
        $this->line($answer->reply);

        foreach (['For the managers' => $answer->replyForManagers, 'Reason' => $answer->reason, 'Summary' => $answer->summary] as $label => $value) {
            if ($value !== '') {
                $this->newLine();
                $this->line("<fg=gray>{$label}:</> {$value}");
            }
        }

        return self::SUCCESS;
    }
}
