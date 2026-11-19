<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Support\Str;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Events\AgentReplied;
use Threadlive\Jobs\RefreshDashboard;
use Threadlive\Jobs\SyncTopic;
use Threadlive\Models\Conversation;
use Threadlive\Support\ConversationActions;

/**
 * One Telegram update: what a manager did in a topic.
 *
 *   a message                → sent to the visitor (the first replier is assigned)
 *   a message starting //    → an internal note, never shown to the visitor
 *   /close                   → closes the conversation and the topic
 *   an edited reply          → the visitor sees the new text
 *   🙋 Take / ✅ Close        → assign / close
 *
 * Anything else — other chats, bots (this one included), the General
 * topic, service messages, media (phase 2) — is ignored.
 */
final class UpdateHandler
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /**
     * @param  array<mixed>  $update
     */
    public function handle(array $update): void
    {
        if (is_array($update['callback_query'] ?? null)) {
            $this->callback($update['callback_query']);

            return;
        }

        $edited = is_array($update['edited_message'] ?? null);
        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (! is_array($message) || ! $this->fromOurGroup($message)) {
            return;
        }

        if ($this->isOwnRename($message)) {
            $this->deleteServiceMessage($message);

            return;
        }

        if (($message['from']['is_bot'] ?? false) === true) {
            return;
        }

        $thread = $message['message_thread_id'] ?? null;
        if (! is_int($thread) || ($message['is_topic_message'] ?? false) !== true) {
            return;
        }

        $conversation = Conversation::query()->where('telegram_thread_id', $thread)->first();
        $text = $message['text'] ?? null;

        if ($conversation === null || ! is_string($text) || trim($text) === '' || ! is_int($message['message_id'] ?? null)) {
            return;
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];

        if ($edited) {
            $this->edited($conversation, $message['message_id'], $text);

            return;
        }

        $trimmed = trim($text);

        if (str_starts_with($trimmed, '//')) {
            $conversation->messages()->create([
                'direction' => Direction::Note,
                'body' => $trimmed,
                'telegram_message_id' => $message['message_id'],
                'telegram_from_id' => is_int($from['id'] ?? null) ? $from['id'] : null,
                'agent_name' => $this->name($from),
            ]);

            return;
        }

        if (preg_match('/^\/close(@\w+)?$/i', $trimmed) === 1) {
            $this->close($conversation);

            return;
        }

        $this->reply($conversation, $message['message_id'], $trimmed, $from);
    }

    /**
     * @param  array<mixed>  $from
     */
    private function reply(Conversation $conversation, int $telegramMessageId, string $text, array $from): void
    {
        $name = $this->name($from);

        if (config('threadlive.assign_on_first_reply', true) && $conversation->assignee_name === null) {
            $this->assign($conversation, $from);
        }

        $message = $conversation->messages()->create([
            'direction' => Direction::Agent,
            'body' => $text,
            'telegram_message_id' => $telegramMessageId,
            'telegram_from_id' => is_int($from['id'] ?? null) ? $from['id'] : null,
            'agent_name' => $name,
        ]);

        $now = now();
        $conversation->forceFill([
            'status' => ConversationStatus::Answered,
            'closed_at' => null,
            'closed_by' => null,
            'last_agent_message_at' => $now,
            'last_activity_at' => $now,
            'unread_email_sent_at' => null,
        ])->save();

        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();
        event(new AgentReplied($conversation, $message));
    }

    private function edited(Conversation $conversation, int $telegramMessageId, string $text): void
    {
        $message = $conversation->messages()
            ->where('telegram_message_id', $telegramMessageId)
            ->whereIn('direction', [Direction::Agent, Direction::Note])
            ->first();

        // Turning a reply into a note (or back) after the fact is not a thing:
        // the visitor may already have read it. Only the text changes.
        $message?->forceFill(['body' => trim($text), 'edited_at' => now()])->save();
    }

    private function close(Conversation $conversation): void
    {
        ConversationActions::close($conversation, 'agent');
    }

    /**
     * @param  array<mixed>  $from
     */
    private function assign(Conversation $conversation, array $from): void
    {
        $name = $this->name($from);

        $conversation->messages()->create(['direction' => Direction::System, 'body' => "{$name} joined the chat"]);
        $conversation->forceFill([
            'assignee_tg_id' => is_int($from['id'] ?? null) ? $from['id'] : null,
            'assignee_name' => $name,
            'assigned_at' => now(),
        ])->save();
    }

    /**
     * @param  array<mixed>  $query
     */
    private function callback(array $query): void
    {
        $id = is_string($query['id'] ?? null) ? $query['id'] : null;
        $data = is_string($query['data'] ?? null) ? $query['data'] : '';
        $message = is_array($query['message'] ?? null) ? $query['message'] : [];
        $from = is_array($query['from'] ?? null) ? $query['from'] : [];

        if ($id === null || ! $this->fromOurGroup($message) || preg_match('/^tl:(claim|close):(\d+)$/', $data, $m) !== 1) {
            return;
        }

        $conversation = Conversation::query()->find((int) $m[2]);
        if ($conversation === null) {
            $this->answer($id, 'This conversation no longer exists.');

            return;
        }

        if ($m[1] === 'close') {
            $this->close($conversation);
            $this->answer($id, 'Closed.');

            return;
        }

        if (! config('threadlive.claim_button', true)) {
            // Turned off since this card was posted: say so, and drop the button.
            $this->answer($id, 'Taking conversations is turned off.');
            $this->dropTakeButton($conversation, $message);

            return;
        }

        $this->assign($conversation, $from);
        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();
        $this->answer($id, 'It’s yours.');
    }

    /**
     * @param  array<mixed>  $message
     */
    private function dropTakeButton(Conversation $conversation, array $message): void
    {
        if (! is_int($message['message_id'] ?? null)) {
            return;
        }

        try {
            $this->telegram->call('editMessageReplyMarkup', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_id' => $message['message_id'],
                'reply_markup' => ['inline_keyboard' => VisitorCard::keyboard($conversation)],
            ]);
        } catch (TelegramException $e) {
            report($e);
        }
    }

    private function answer(string $callbackId, string $text): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
        } catch (TelegramException $e) {
            report($e);
        }
    }

    /**
     * "threadlive renamed the topic to …": Telegram posts one in the topic on
     * every rename, and the package renames on every status change. The name
     * itself shows the status, so the line is only noise in the conversation.
     * Renames made by people stay.
     *
     * A bot's id is the part of its token before the colon.
     *
     * @param  array<mixed>  $message
     */
    private function isOwnRename(array $message): bool
    {
        $botId = Str::before((string) config('threadlive.bot_token'), ':');

        return is_array($message['forum_topic_edited'] ?? null)
            && ($message['from']['is_bot'] ?? false) === true
            && $botId !== ''
            && (string) ($message['from']['id'] ?? '') === $botId;
    }

    /**
     * @param  array<mixed>  $message
     */
    private function deleteServiceMessage(array $message): void
    {
        if (! is_int($message['message_id'] ?? null)) {
            return;
        }

        try {
            $this->telegram->call('deleteMessage', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_id' => $message['message_id'],
            ]);
        } catch (TelegramException $e) {
            // Without "Delete messages" the line just stays; setup reports the right.
            report($e);
        }
    }

    /**
     * @param  array<mixed>  $message
     */
    private function fromOurGroup(array $message): bool
    {
        $chatId = $message['chat']['id'] ?? null;

        return (is_int($chatId) || is_string($chatId)) && (string) $chatId === (string) config('threadlive.chat_id');
    }

    /**
     * @param  array<mixed>  $from
     */
    private function name(array $from): string
    {
        $name = trim((is_string($from['first_name'] ?? null) ? $from['first_name'] : '').' '.(is_string($from['last_name'] ?? null) ? $from['last_name'] : ''));

        return $name !== '' ? $name : 'Support';
    }
}
