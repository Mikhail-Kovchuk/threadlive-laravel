<?php

declare(strict_types=1);

namespace Threadlive\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Threadlive\Assistant\AssistantService;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Models\Conversation;
use Threadlive\Support\ConversationActions;
use Threadlive\Telegram\AssistantPosts;
use Throwable;

/**
 * The assistant answers a conversation the visitor wrote last in — after
 * their messages are in the topic, so the topic reads in order. Anything
 * that is not an answer (an error, the limits, the AI's own "handoff")
 * hands the conversation to the managers: a visitor is never left waiting
 * on the AI.
 */
final class AnswerWithAssistant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** The widget shows "typing…" this long at most. */
    private const TYPING_SECONDS = 90;

    /** Any real error hands off at once (failed()); never asking the AI twice. */
    public int $maxExceptions = 1;

    public function __construct(public readonly int $conversationId)
    {
        DeliverVisitorMessages::useConfiguredQueue($this);
    }

    /** Waiting behind another answer of the same conversation is not a failure. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('threadlive:assistant:'.$this->conversationId))->releaseAfter(3)->expireAfter(120)];
    }

    public static function typingKey(int $conversationId): string
    {
        return 'threadlive:assistant:typing:'.$conversationId;
    }

    public static function isTyping(int $conversationId): bool
    {
        return Cache::has(self::typingKey($conversationId));
    }

    /** "Typing…" for the widget, then the answer. */
    public static function start(int $conversationId): void
    {
        self::typing($conversationId);
        self::dispatch($conversationId);
    }

    public static function typing(int $conversationId): void
    {
        Cache::put(self::typingKey($conversationId), true, now()->addSeconds(self::TYPING_SECONDS));
    }

    public function handle(AssistantService $assistant, AssistantPosts $posts): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null || ! $conversation->withAssistant() || $conversation->status === ConversationStatus::Closed) {
            Cache::forget(self::typingKey($this->conversationId));

            return;
        }

        $last = $conversation->messages()->whereIn('direction', [Direction::Visitor, Direction::Agent])->latest('id')->first();
        if ($last === null || $last->direction !== Direction::Visitor) {
            Cache::forget(self::typingKey($conversation->id));

            return; // answered already
        }

        $driver = $assistant->enabled() ? $assistant->driver() : null;
        if ($driver === null) {
            ConversationActions::handOff($conversation, 'The assistant is switched off.');

            return;
        }

        if ($conversation->assistant_replies >= $assistant->maxReplies()) {
            ConversationActions::handOff($conversation, "The assistant has answered {$conversation->assistant_replies} times already.");

            return;
        }

        if (! $assistant->takeFromDailyLimit()) {
            ConversationActions::handOff($conversation, 'The assistant\'s daily limit is reached.');

            return;
        }

        try {
            $answer = $driver->answer($assistant->instructions($conversation), $assistant->conversationText($conversation));
        } catch (Throwable $e) {
            Log::warning('Threadlive: the assistant could not answer conversation '.$conversation->id.': '.$e->getMessage());
            ConversationActions::handOff($conversation, 'The assistant could not answer ('.Str::limit($e->getMessage(), 120).').');

            return;
        }

        // The visitor wrote again meanwhile: that message's own job answers
        // both. A manager took over: theirs.
        $conversation->refresh();
        $newest = (int) $conversation->messages()->where('direction', Direction::Visitor)->max('id');
        if ($newest !== $last->id || ! $conversation->withAssistant()) {
            return;
        }

        if ($answer->language !== '' && $conversation->visitor_language === null) {
            $conversation->forceFill(['visitor_language' => $answer->language])->save();
        }

        if ($answer->handoff) {
            ConversationActions::handOff(
                $conversation,
                $answer->reason !== '' ? $answer->reason : 'The assistant cannot help with this.',
                $answer->summary,
                $answer->reply,
                $answer->replyForManagers,
            );

            return;
        }

        // Counted only while it is still the assistant's: a manager taking
        // over at this very moment wins.
        $counted = Conversation::query()
            ->whereKey($conversation->id)
            ->where('handler', Conversation::AI)
            ->update([
                'assistant_replies' => $conversation->assistant_replies + 1,
                'status' => ConversationStatus::Answered->value,
                'last_activity_at' => now(),
            ]);

        if ($counted === 0) {
            return;
        }

        $conversation->refresh();
        $message = $conversation->messages()->create([
            'direction' => Direction::Agent,
            'body' => $answer->reply,
            'agent_name' => $assistant->name(),
            'by_assistant' => true,
        ]);
        Cache::forget(self::typingKey($conversation->id));

        // The visitor has the answer; the topic's copy is for the managers'
        // eyes, and is not worth asking the AI again for.
        try {
            $posts->reply($conversation, $message, $answer->replyForManagers, $assistant->managersLanguage());
        } catch (Throwable $e) {
            Log::warning('Threadlive: the assistant\'s answer in conversation '.$conversation->id.' did not reach the topic: '.$e->getMessage());
        }

        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();
    }

    /** Whatever broke, the managers take the conversation. */
    public function failed(?Throwable $exception): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        Cache::forget(self::typingKey($this->conversationId));

        if ($conversation !== null) {
            ConversationActions::handOff($conversation, 'The assistant failed ('.Str::limit((string) $exception?->getMessage(), 120).').');
        }
    }
}
