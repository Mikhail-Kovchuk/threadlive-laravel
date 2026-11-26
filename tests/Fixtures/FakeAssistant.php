<?php

declare(strict_types=1);

namespace Threadlive\Tests\Fixtures;

use Threadlive\Assistant\AssistantAnswer;
use Threadlive\Contracts\Assistant;

/**
 * Answers with what a test put in $next (an answer, or a Throwable to
 * throw); "We are open 9 to 6." when nothing was put. Keeps what it was given.
 */
final class FakeAssistant implements Assistant
{
    public static AssistantAnswer|\Throwable|null $next = null;

    /** Runs before answering: to change the world while the AI "thinks". */
    public static ?\Closure $before = null;

    /** @var list<array{string, string}> instructions, conversation */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$next = null;
        self::$before = null;
        self::$calls = [];
    }

    public function answer(string $instructions, string $conversation): AssistantAnswer
    {
        self::$calls[] = [$instructions, $conversation];

        if (self::$before !== null) {
            (self::$before)();
        }

        $next = self::$next ?? new AssistantAnswer(false, 'We are open 9 to 6.', 'en');

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
