<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use Threadlive\Contracts\ManagerCommand;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Events\AgentReplied;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Jobs\RefreshDashboard;
use Threadlive\Jobs\SyncTopic;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Support\ConversationActions;
use Threadlive\Translation\Languages;
use Threadlive\Translation\TranslationService;

/**
 * One Telegram update: what a manager did in a topic.
 *
 *   a message                → sent to the visitor (the first replier is assigned)
 *   a message starting //    → an internal note, never shown to the visitor
 *   /close                   → closes the conversation and the topic
 *   an edited reply          → the visitor sees the new text
 *   🙋 Take / ✅ Close        → assign / close
 *   /name argument (anywhere) → the app's ManagerCommand answers, managers only
 *
 * Anything else — other chats, bots (this one included), the General
 * topic, service messages, media (phase 2) — is ignored.
 */
final class UpdateHandler
{
    /** Cache key of a "send the code in reply" prompt → the command waiting for it. */
    private const PROMPT_KEY = 'threadlive:prompt:';

    private const PROMPT_TTL = 3600;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly Commands $commands,
        private readonly Cache $cache,
        private readonly TranslationService $translation,
    ) {}

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

        // Before anything goes to a visitor: a command, in a topic or in
        // General, is for the managers only.
        $text = $message['text'] ?? null;
        if (! $edited && is_string($text) && is_int($message['message_id'] ?? null) && $this->managerCommand($message, trim($text))) {
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

        if (preg_match('/^\/translate(?:@\w+)?(?:\s+([A-Za-z-]+))?$/i', $trimmed, $m) === 1) {
            $this->toggleTranslation($conversation, $message, strtolower($m[1] ?? ''));

            return;
        }

        $this->reply($conversation, $message['message_id'], $trimmed, $from, $message);
    }

    /**
     * /translate: on with the default language, or off when on.
     * /translate ru: on, into Russian. /translate off: off.
     * For this conversation only.
     *
     * @param  array<mixed>  $message
     */
    private function toggleTranslation(Conversation $conversation, array $message, string $argument): void
    {
        if (! $this->translation->enabled()) {
            $this->replyTo($message, 'Translation is not set up: set threadlive.translation.driver and THREADLIVE_TRANSLATION_KEY.');

            return;
        }

        if ($argument === 'off' || ($argument === '' && $conversation->translate_to !== null)) {
            $conversation->forceFill(['translate_to' => null])->save();
            $this->replyTo($message, '🌐 Translation off for this conversation.');

            return;
        }

        $target = $argument === '' ? $this->translation->defaultTarget() : Languages::base($argument);
        $languages = $this->translation->languages();

        if (! in_array($target, $languages, true)) {
            $this->replyTo($message, 'Choose one of: '.implode(', ', $languages).' — or /translate off.');

            return;
        }

        $conversation->forceFill(['translate_to' => $target])->save();

        // What the visitor wrote last, translated now: it tells their
        // language, and the managers what they missed.
        $last = $conversation->messages()->where('direction', Direction::Visitor)->latest('id')->first();
        $translation = $last === null ? null : $this->translation->translate($last->body, $target);

        if ($translation !== null && $translation->source !== '') {
            $conversation->forceFill(['visitor_language' => $translation->source])->save();
        }

        $lines = ['🌐 Translation on: the visitor\'s messages come with a translation into <b>'.Languages::label($target).'</b>.'];
        $lines[] = $conversation->visitor_language !== null
            ? 'Your replies go out in <b>'.Languages::label($conversation->visitor_language).'</b>.'
            : 'Your replies go out in the visitor\'s language once they write.';

        if ($last !== null && $translation !== null && ! $translation->isSameLanguage()) {
            $lines[] = '';
            $lines[] = 'Their last message:';
            $lines[] = DeliverVisitorMessages::quote(e($last->body)).e($translation->text);
        }

        $this->replyTo($message, implode("\n", $lines));
    }

    /**
     * With /translate on, the visitor gets the reply in their language, and
     * the topic a silent "🌐 → ES: …" under the reply, so the manager sees
     * what went out. Without a translation (off, failed, same language) the
     * reply goes as written.
     *
     * @param  array<mixed>  $telegramMessage
     */
    private function translateReply(Conversation $conversation, Message $reply, array $telegramMessage): void
    {
        $language = $conversation->visitor_language;

        if ($conversation->translate_to === null || $language === null) {
            return;
        }

        $translation = $this->translation->translate($reply->body, $language);

        if ($translation === null || $translation->isSameLanguage()) {
            $reply->forceFill(['translation' => null])->save();

            return;
        }

        $reply->forceFill(['translation' => $translation->toArray()])->save();
        // Quoted, like everything in the visitor's language in the topic.
        $this->replyTo($telegramMessage, '🌐 <b>→ '.Languages::label($language)."</b>\n"
            .DeliverVisitorMessages::quote(e($translation->text)));
    }

    /**
     * @param  array<mixed>  $from
     * @param  array<mixed>  $telegramMessage
     */
    private function reply(Conversation $conversation, int $telegramMessageId, string $text, array $from, array $telegramMessage): void
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

        $this->translateReply($conversation, $message, $telegramMessage);

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

        if ($message === null) {
            return;
        }

        // Turning a reply into a note (or back) after the fact is not a thing:
        // the visitor may already have read it. Only the text changes — and
        // its translation, quietly, when there is one to keep up to date.
        $message->forceFill(['body' => trim($text), 'edited_at' => now()])->save();

        $language = $conversation->visitor_language;
        if ($message->direction === Direction::Agent && $conversation->translate_to !== null && $language !== null) {
            $translation = $this->translation->translate($message->body, $language);
            $message->forceFill([
                'translation' => $translation === null || $translation->isSameLanguage() ? null : $translation->toArray(),
            ])->save();
        }
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
     * "/lookup A-1", "/lookup" (the bot asks for the argument), or the reply
     * to that question. True when the message was a command, handled or not:
     * it is never passed on to a visitor. /close is the topic's own (false).
     *
     * @param  array<mixed>  $message
     */
    private function managerCommand(array $message, string $text): bool
    {
        $repliedTo = $message['reply_to_message']['message_id'] ?? null;
        $waiting = is_int($repliedTo) ? $this->cache->get(self::PROMPT_KEY.$repliedTo) : null;

        if (is_string($waiting)) {
            $this->cache->forget(self::PROMPT_KEY.$repliedTo);
            $command = $this->commands->all()[$waiting] ?? null;
            if ($command !== null) {
                $this->answerCommand($message, $command, $text);
            }

            return true;
        }

        if (preg_match('/^\/([A-Za-z0-9_]+)(@\w+)?(?:\s+(.*))?$/s', $text, $m) !== 1) {
            return false;
        }

        $name = strtolower($m[1]);
        if (in_array($name, Commands::RESERVED, true)) {
            return false; // the topic's own: /close, /translate
        }

        $commands = array_map(fn (ManagerCommand $c) => $c->description(), $all = $this->commands->all());

        if (! array_key_exists($name, $commands)) {
            // "/start@SomeOtherBot" is someone else's; a bare one is ours.
            if (($m[2] ?? '') === '') {
                $this->replyTo($message, $commands === []
                    ? 'This bot has no commands besides /close.'
                    : 'Unknown command /'.e($name).'. Try '.implode(', ', array_map(fn (string $c) => '/'.$c, array_keys($commands))).'.');
            }

            return true;
        }

        $argument = trim($m[3] ?? '');

        if ($argument === '') {
            $prompt = $this->replyTo($message, '🔎 '.e($commands[$name]).' — send it in reply to this message.', [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' => mb_substr($commands[$name], 0, 64),
                ],
            ]);
            if ($prompt !== null) {
                $this->cache->put(self::PROMPT_KEY.$prompt, $name, self::PROMPT_TTL);
            }

            return true;
        }

        $this->answerCommand($message, $all[$name], $argument);

        return true;
    }

    /**
     * @param  array<mixed>  $message
     */
    private function answerCommand(array $message, ManagerCommand $command, string $argument): void
    {
        try {
            $reply = $command->handle(trim($argument));
        } catch (\Throwable $e) {
            report($e);
            $this->replyTo($message, '/'.e($command->name()).' failed; the details are in the application log.');

            return;
        }

        $this->replyTo($message, $reply === null
            ? 'Nothing found for “'.e(trim($argument)).'”.'
            : $reply->toHtml());
    }

    /**
     * A silent reply in the same topic (or General).
     *
     * @param  array<mixed>  $message
     * @param  array<string, mixed>  $extra
     * @return int|null the reply's message id
     */
    private function replyTo(array $message, string $html, array $extra = []): ?int
    {
        $inTopic = is_int($message['message_thread_id'] ?? null) && ($message['is_topic_message'] ?? false) === true;

        try {
            $result = $this->telegram->call('sendMessage', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_thread_id' => $inTopic ? $message['message_thread_id'] : null,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_notification' => true,
                'link_preview_options' => ['is_disabled' => true],
                'reply_parameters' => ['message_id' => $message['message_id'], 'allow_sending_without_reply' => true],
                ...$extra,
            ]);
        } catch (TelegramException $e) {
            report($e);

            return null;
        }

        return is_array($result) && is_int($result['message_id'] ?? null) ? $result['message_id'] : null;
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
