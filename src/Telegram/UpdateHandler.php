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
use Threadlive\Support\Attachments;
use Threadlive\Support\ConversationActions;
use Threadlive\Translation\Languages;
use Threadlive\Translation\Translation;
use Threadlive\Translation\TranslationService;

/**
 * One Telegram update: what a manager did in a topic.
 *
 *   a message                → sent to the visitor (the first replier is assigned)
 *   a message starting //    → an internal note, never shown to the visitor
 *   /close                   → closes the conversation and the topic
 *   an edited reply          → the visitor sees the new text
 *   🙋 Take / ✅ Close        → assign / close
 *   /find what (anywhere)    → conversations by #number, e-mail, name or words
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
        // Telegram sends an update again when the answer to it was slow (a
        // file download, a translation): handled once.
        if (is_int($update['update_id'] ?? null) && ! $this->cache->add('threadlive:update:'.$update['update_id'], true, now()->addDay())) {
            return;
        }

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
        // A photo or document carries its text as a caption.
        $text = $message['text'] ?? $message['caption'] ?? '';
        $hasMedia = is_array($message['photo'] ?? null) || is_array($message['document'] ?? null);

        if ($conversation === null || ! is_string($text) || ! is_int($message['message_id'] ?? null) || (trim($text) === '' && ! $hasMedia)) {
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

        $files = $this->managerFiles($conversation, $message);
        if ($files === null || ($trimmed === '' && $files === [])) {
            return;
        }

        $this->reply($conversation, $message['message_id'], $trimmed, $from, $message, $files);
    }

    /**
     * The photo or document of a manager's message, fetched from Telegram
     * and stored for the visitor. [] when there is none; null when there is
     * one the visitor must not get — the topic is told why, and the message
     * goes nowhere.
     *
     * @param  array<mixed>  $message
     * @return list<array{id: string, disk: string, path: string, name: string, mime: string, size: int}>|null
     */
    private function managerFiles(Conversation $conversation, array $message): ?array
    {
        $media = null;

        if (is_array($message['photo'] ?? null) && $message['photo'] !== []) {
            // Sizes from small to large: the last is the original.
            $largest = end($message['photo']);
            if (is_array($largest) && is_string($largest['file_id'] ?? null)) {
                $media = [
                    'file_id' => $largest['file_id'],
                    'size' => is_int($largest['file_size'] ?? null) ? $largest['file_size'] : 0,
                    'name' => 'photo.jpg',
                    'extension' => 'jpg',
                    'mime' => 'image/jpeg',
                ];
            }
        } elseif (is_array($message['document'] ?? null) && is_string($message['document']['file_id'] ?? null)) {
            $document = $message['document'];
            $name = is_string($document['file_name'] ?? null) ? $document['file_name'] : 'file';
            $media = [
                'file_id' => $document['file_id'],
                'size' => is_int($document['file_size'] ?? null) ? $document['file_size'] : 0,
                'name' => $name,
                'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'mime' => is_string($document['mime_type'] ?? null) ? $document['mime_type'] : null,
            ];
        }

        if ($media === null) {
            return [];
        }

        $refuse = match (true) {
            ! Attachments::enabled() => 'Not sent to the visitor: files are turned off (threadlive.attachments.enabled).',
            ! Attachments::allowsExtension($media['extension']) => 'Not sent to the visitor: send '.Attachments::typesForPeople().' files.',
            $media['size'] > Attachments::maxKb() * 1024 => 'Not sent to the visitor: the file is over '.round(Attachments::maxKb() / 1024, 1).' MB.',
            default => null,
        };

        if ($refuse !== null) {
            $this->replyTo($message, $refuse);

            return null;
        }

        try {
            $download = $this->telegram->download($media['file_id']);
        } catch (TelegramException $e) {
            report($e);
            $this->replyTo($message, 'Not sent to the visitor: the bot could not fetch the file (Telegram lets bots fetch up to 20 MB).');

            return null;
        }

        return [Attachments::storeContents($download['contents'], $media['name'], $media['extension'], $conversation, $media['mime'])];
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
     * what went out. Null — the reply goes as written — when translation is
     * off, fails, or the reply is already in that language.
     */
    private function replyTranslation(Conversation $conversation, string $text): ?Translation
    {
        $language = $conversation->visitor_language;

        if ($conversation->translate_to === null || $language === null || trim($text) === '') {
            return null;
        }

        $translation = $this->translation->translate($text, $language);

        return $translation === null || $translation->isSameLanguage() ? null : $translation;
    }

    /**
     * @param  array<mixed>  $from
     * @param  array<mixed>  $telegramMessage
     * @param  list<array<string, mixed>>  $files
     */
    private function reply(Conversation $conversation, int $telegramMessageId, string $text, array $from, array $telegramMessage, array $files = []): void
    {
        $name = $this->name($from);

        if (config('threadlive.assign_on_first_reply', true) && $conversation->assignee_name === null) {
            $this->assign($conversation, $from, onlyIfFree: true);
        }

        // Translated before it is stored: a poll in between would otherwise
        // show the visitor the manager's original, and never ask again.
        $translation = $this->replyTranslation($conversation, $text);

        $message = $conversation->messages()->create([
            'direction' => Direction::Agent,
            'body' => $text,
            'translation' => $translation?->toArray(),
            'telegram_message_id' => $telegramMessageId,
            'telegram_from_id' => is_int($from['id'] ?? null) ? $from['id'] : null,
            'agent_name' => $name,
            'attachments' => $files === [] ? null : $files,
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

        if ($translation !== null && $conversation->visitor_language !== null) {
            // Quoted, like everything in the visitor's language in the topic.
            $this->replyTo($telegramMessage, '🌐 <b>→ '.Languages::label($conversation->visitor_language).'</b>
'
                .DeliverVisitorMessages::quote(e($translation->text)));
        }

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
    private function assign(Conversation $conversation, array $from, bool $onlyIfFree = false): void
    {
        $name = $this->name($from);
        $attributes = [
            'assignee_tg_id' => is_int($from['id'] ?? null) ? $from['id'] : null,
            'assignee_name' => $name,
            'assigned_at' => now(),
        ];

        // Two managers answering at once: one update finds it free, the
        // other finds it taken — one "joined the chat", not two.
        $taken = Conversation::query()
            ->whereKey($conversation->id)
            ->when($onlyIfFree, fn ($q) => $q->whereNull('assignee_name'))
            ->update($attributes);

        if ($taken === 0) {
            $conversation->refresh();

            return;
        }

        $conversation->forceFill($attributes)->syncOriginal();
        $conversation->messages()->create(['direction' => Direction::System, 'body' => "{$name} joined the chat"]);
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
        if (in_array($name, Commands::TOPIC_OWN, true)) {
            return false; // the topic's own: /close, /translate
        }

        $commands = array_map(fn (ManagerCommand $c) => $c->description(), $all = $this->commands->all());

        if (! array_key_exists($name, $commands)) {
            // "/start@SomeOtherBot" is someone else's; a bare one is ours.
            if (($m[2] ?? '') === '') {
                $this->replyTo($message, 'Unknown command /'.e($name).'. Try '.implode(', ', array_map(fn (string $c) => '/'.$c, array_keys($commands))).'.');
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
