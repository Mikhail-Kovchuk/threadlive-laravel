<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Support\Str;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Delivery;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;

/**
 * The conversation's forum topic: a disposable view of what is in our
 * database. Deleted in Telegram (by hand, or by retention) it is simply
 * created again, with a summary of what came before.
 */
final class Topics
{
    private const SUMMARY_MESSAGES = 5;

    public function __construct(private readonly TelegramClient $telegram) {}

    public function chatId(): string
    {
        return (string) config('threadlive.chat_id');
    }

    /**
     * The topic to post in: as it is when open, reopened when closed,
     * created when there is none (or it was deleted).
     *
     * @throws TelegramException
     */
    public function ensureOpen(Conversation $conversation): int
    {
        $thread = $conversation->telegram_thread_id;

        if ($thread !== null && $conversation->topic_state === TopicState::Open) {
            return $thread;
        }

        if ($thread !== null && $conversation->topic_state === TopicState::Closed) {
            try {
                $this->telegram->call('reopenForumTopic', ['chat_id' => $this->chatId(), 'message_thread_id' => $thread]);
            } catch (TelegramException $e) {
                if ($e->isMissingTopic()) {
                    return $this->create($conversation, returning: true);
                }
                if (! $e->isNotModified()) {
                    throw $e;
                }
            }

            $conversation->forceFill(['topic_state' => TopicState::Open, 'topic_closed_at' => null])->save();

            return $thread;
        }

        return $this->create($conversation, returning: $thread !== null);
    }

    /**
     * Bring the topic in line with the conversation: closed or open, and the
     * name. A topic found missing is marked deleted; the next visitor message
     * creates a new one.
     *
     * @throws TelegramException
     */
    public function sync(Conversation $conversation): void
    {
        $thread = $conversation->telegram_thread_id;

        if ($thread === null || ! in_array($conversation->topic_state, [TopicState::Open, TopicState::Closed], true)) {
            return;
        }

        try {
            $this->rename($conversation);

            if ($conversation->status === ConversationStatus::Closed && $conversation->topic_state === TopicState::Open) {
                $this->telegram->call('closeForumTopic', ['chat_id' => $this->chatId(), 'message_thread_id' => $thread]);
                $conversation->forceFill(['topic_state' => TopicState::Closed, 'topic_closed_at' => now()])->save();
            } elseif ($conversation->status !== ConversationStatus::Closed && $conversation->topic_state === TopicState::Closed) {
                $this->telegram->call('reopenForumTopic', ['chat_id' => $this->chatId(), 'message_thread_id' => $thread]);
                $conversation->forceFill(['topic_state' => TopicState::Open, 'topic_closed_at' => null])->save();
            }
        } catch (TelegramException $e) {
            if ($e->isMissingTopic()) {
                $conversation->forceFill(['topic_state' => TopicState::Deleted, 'topic_deleted_at' => now()])->save();

                return;
            }
            if (! $e->isNotModified()) {
                throw $e;
            }
        }
    }

    /** Only when the name changes: Telegram limits how often a topic may be edited. */
    private function rename(Conversation $conversation): void
    {
        $name = TopicName::for($conversation);

        if ($name === $conversation->topic_name) {
            return;
        }

        try {
            $this->telegram->call('editForumTopic', [
                'chat_id' => $this->chatId(),
                'message_thread_id' => $conversation->telegram_thread_id,
                'name' => $name,
            ]);
        } catch (TelegramException $e) {
            if (! $e->isNotModified()) {
                throw $e;
            }
        }

        $conversation->forceFill(['topic_name' => $name])->save();
    }

    /** @throws TelegramException */
    private function create(Conversation $conversation, bool $returning): int
    {
        $name = TopicName::for($conversation);
        $topic = $this->telegram->call('createForumTopic', ['chat_id' => $this->chatId(), 'name' => $name]);
        $thread = is_array($topic) && is_int($topic['message_thread_id'] ?? null) ? $topic['message_thread_id'] : null;

        if ($thread === null) {
            throw new TelegramException('Telegram createForumTopic: no message_thread_id in the answer.');
        }

        $conversation->forceFill([
            'telegram_thread_id' => $thread,
            'topic_state' => TopicState::Open,
            'topic_name' => $name,
            'topic_closed_at' => null,
            'topic_deleted_at' => null,
        ])->save();

        $this->telegram->sendMessage(
            $this->chatId(),
            VisitorCard::html($conversation),
            $thread,
            silent: true,
            keyboard: VisitorCard::keyboard($conversation),
        );

        if ($returning && ($summary = $this->summary($conversation)) !== null) {
            $this->telegram->sendMessage($this->chatId(), $summary, $thread, silent: true);
        }

        return $thread;
    }

    /** What the managers need from a topic that no longer exists. */
    private function summary(Conversation $conversation): ?string
    {
        $previous = $conversation->messages()
            ->where(fn ($q) => $q
                ->where('direction', Direction::Agent)
                ->orWhere(fn ($q) => $q->where('direction', Direction::Visitor)->where('delivery', Delivery::Sent)))
            ->orderByDesc('id')
            ->limit(self::SUMMARY_MESSAGES)
            ->get()
            ->reverse();

        $last = $previous->last();
        if ($last === null) {
            return null;
        }

        $lines = $previous->map(fn (Message $m) => ($m->direction === Direction::Agent
            ? '🧑‍💼 '.e($m->agent_name ?: 'Support')
            : '👤 Visitor').': '.e(Str::limit($m->body, 200)));

        return '↩️ <b>Returning visitor</b> — previous conversation '
            .e($last->created_at?->format('j M Y') ?? '')
            .", last messages:\n".$lines->implode("\n");
    }
}
