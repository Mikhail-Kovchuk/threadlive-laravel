<?php

declare(strict_types=1);

namespace Threadlive\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Threadlive\Assistant\AssistantService;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Events\ConversationClosed;
use Threadlive\Jobs\AnnounceHandoff;
use Threadlive\Jobs\AnswerWithAssistant;
use Threadlive\Jobs\RefreshDashboard;
use Threadlive\Jobs\SyncTopic;
use Threadlive\Models\Conversation;

/** What more than one place does to a conversation. */
final class ConversationActions
{
    /**
     * Closes it: the visitor sees "Conversation closed", the topic closes
     * with ✅. Writing again reopens it.
     *
     * @param  'agent'|'auto'  $by
     * @return bool false when it was closed already
     */
    public static function close(Conversation $conversation, string $by): bool
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return false;
        }

        // Only as it was when loaded: a visitor message stored meanwhile
        // (the auto-close sweep, a manager's slow /close) keeps it open.
        $attributes = ['status' => ConversationStatus::Closed, 'closed_at' => now(), 'closed_by' => $by];
        $lastVisitor = $conversation->last_visitor_message_at;
        $closed = Conversation::query()
            ->whereKey($conversation->id)
            ->where('status', $conversation->status)
            ->when(
                $lastVisitor === null,
                fn ($q) => $q->whereNull('last_visitor_message_at'),
                fn ($q) => $q->where('last_visitor_message_at', $lastVisitor),
            )
            ->update($attributes);

        if ($closed === 0) {
            $conversation->refresh();

            return false;
        }

        $conversation->forceFill($attributes)->syncOriginal();
        $conversation->messages()->create(['direction' => Direction::System, 'body' => 'Conversation closed']);

        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();
        event(new ConversationClosed($conversation));

        return true;
    }

    /**
     * The assistant's conversation to the managers: waiting (❗) again, and
     * — unless a manager took it themselves — a post with sound in the topic
     * saying why, with the assistant's summary.
     *
     * @param  string|null  $notice  what the visitor is told, in their language
     *                               (the assistant's words); null: a system line
     * @param  string  $noticeForManagers  that notice in the managers' language
     * @return bool false when it was the managers' already
     */
    public static function handOff(
        Conversation $conversation,
        string $reason,
        string $summary = '',
        ?string $notice = null,
        string $noticeForManagers = '',
        bool $byManager = false,
    ): bool {
        $attributes = [
            'handler' => Conversation::HUMAN,
            'handed_off_at' => now(),
            'handoff_reason' => Str::limit($reason, 490),
        ];

        // Once: the assistant and a manager (or two jobs) at the same moment.
        $taken = Conversation::query()->whereKey($conversation->id)->where('handler', Conversation::AI)->update($attributes);
        if ($taken === 0) {
            $conversation->refresh();

            return false;
        }

        $conversation->forceFill($attributes)->syncOriginal();
        Cache::forget(AnswerWithAssistant::typingKey($conversation->id));

        if ($byManager) {
            SyncTopic::dispatch($conversation->id);
            RefreshDashboard::dispatch();

            return true;
        }

        if ($conversation->status !== ConversationStatus::Closed) {
            // A new wait for the managers: the waiting alert counts from now.
            $conversation->forceFill([
                'status' => ConversationStatus::Waiting,
                'waiting_alert_sent_at' => null,
                'last_activity_at' => now(),
            ])->save();
        }

        $message = $notice !== null && trim($notice) !== ''
            ? $conversation->messages()->create([
                'direction' => Direction::Agent,
                'body' => trim($notice),
                'by_assistant' => true,
                'agent_name' => app(AssistantService::class)->name(),
            ])
            : $conversation->messages()->create(['direction' => Direction::System, 'body' => 'A manager will continue this conversation here.']);

        AnnounceHandoff::dispatch($conversation->id, $summary, $message->by_assistant ? $message->id : null, $noticeForManagers);
        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();

        return true;
    }

    /**
     * /ai on: the assistant answers this conversation again — at once, when
     * the visitor wrote last.
     */
    public static function backToAssistant(Conversation $conversation): void
    {
        $conversation->forceFill([
            'handler' => Conversation::AI,
            'handed_off_at' => null,
            'handoff_reason' => null,
            'assistant_replies' => 0,
        ])->save();

        $last = $conversation->messages()->whereIn('direction', [Direction::Visitor, Direction::Agent])->latest('id')->first();
        if ($last?->direction === Direction::Visitor) {
            AnswerWithAssistant::start($conversation->id);
        }

        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();
    }
}
