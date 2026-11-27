<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use Threadlive\Assistant\AssistantService;
use Threadlive\Contracts\ManagerCommand;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Events\AgentReplied;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Jobs\RefreshDashboard;
use Threadlive\Jobs\SyncTopic;
use Threadlive\Jobs\TranslateFaq;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Support\Attachments;
use Threadlive\Support\ConversationActions;
use Threadlive\Support\Faq;
use Threadlive\Support\GroupSettings;
use Threadlive\Support\WorkingHours;
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
 *   ⚙️ Actions, /menu         → buttons for translation, the assistant and the app's commands
 *   /ai on|off               → the assistant answers this conversation, or the managers do
 *                              (any manager reply takes it from the assistant too)
 *   /faq (anywhere)          → the FAQ file; a faq.json with that caption replaces it
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

    /** Cache key of a conversation's /menu panel → its message id (one at a time). */
    private const PANEL_KEY = 'threadlive:panel:';

    /** Cache key of this bot's username, for "/command@ThisBot". */
    private const USERNAME_KEY = 'threadlive:bot:username';

    /** Cache key of a "send the new …" question → the setting its reply sets. */
    private const SETTING_PROMPT_KEY = 'threadlive:setting-prompt:';

    /** Cache key of an admin who pressed ✏️ Change, per place → the setting their next message sets. */
    private const SETTING_WAIT_KEY = 'threadlive:setting-wait:';

    private const SETTING_WAIT_MINUTES = 10;

    /** Cache key: this user is an admin of the group (checked again after a while). */
    private const ADMIN_KEY = 'threadlive:admin:';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly Commands $commands,
        private readonly Cache $cache,
        private readonly TranslationService $translation,
        private readonly ActionPanel $panel,
        private readonly AssistantService $assistant,
        private readonly SettingsTopic $settingsTopic,
        private readonly Report $report,
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

        // /faq, or a faq.json with that caption: anywhere, never to a visitor.
        $command = $message['text'] ?? $message['caption'] ?? null;
        if (! $edited && is_string($command) && preg_match('/^\/faq(@\w+)?$/i', trim($command)) === 1) {
            $this->faq($message);

            return;
        }

        // /settings, anywhere: the group's own settings, as buttons.
        if (! $edited && is_string($message['text'] ?? null) && preg_match('/^\/settings(@\w+)?$/i', trim($message['text'])) === 1) {
            $this->postSettings($message);

            return;
        }

        // The answer to a setting's "send the new …" — a reply to it, or the
        // admin's next message in that place: the new value, never a
        // message to a visitor, even in a conversation's topic.
        $waiting = $edited ? null : $this->settingWaitingFor($message);
        if ($waiting !== null) {
            $this->saveSetting($waiting['setting'], $waiting['prompt'], $message);

            return;
        }

        // Before anything goes to a visitor: a command, in a topic or in
        // General, is for the managers only.
        $text = $message['text'] ?? null;
        if (! $edited && is_string($text) && is_int($message['message_id'] ?? null) && $this->managerCommand($message, trim($text))) {
            return;
        }

        $thread = $message['message_thread_id'] ?? null;
        $inTopic = is_int($thread) && ($message['is_topic_message'] ?? false) === true;
        $conversation = $inTopic ? Conversation::query()->where('telegram_thread_id', $thread)->first() : null;

        // /menu, /close, /translate, /ai act on a conversation: in General
        // (or a topic of no conversation) they would do nothing, silently.
        if (! $edited && $conversation === null && is_string($text) && is_int($message['message_id'] ?? null)
            && preg_match('/^\/('.implode('|', Commands::TOPIC_OWN).')(@\w+)?(\s|$)/i', trim($text), $own) === 1) {
            $this->replyTo($message, '/'.strtolower($own[1]).' works in the topic of a conversation — open the visitor\'s topic '
                .'(the dashboard links them) and send it there.');

            return;
        }

        if (! $inTopic) {
            return;
        }
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

        if (preg_match('/^\/menu(@\w+)?$/i', $trimmed) === 1) {
            $this->postPanel($conversation, $message);

            return;
        }

        if (preg_match('/^\/ai(?:@\w+)?(?:\s+(\S+))?$/i', $trimmed, $m) === 1) {
            $this->replyTo($message, $this->switchAssistant($conversation, strtolower($m[1] ?? ''), $from));

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
            $this->replyTo($message, 'Translation is not set up: set threadlive.translation.driver and its key in .env (THREADLIVE_GEMINI_KEY or THREADLIVE_TRANSLATION_KEY).');

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

        // A manager answering takes the conversation from the assistant.
        if ($conversation->withAssistant()) {
            ConversationActions::handOff($conversation, "{$name} took over.", byManager: true);
        }

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

        if ($id === null || ! $this->fromOurGroup($message)) {
            return;
        }

        // The /settings panel's buttons: about the group, not a conversation.
        // Posted where the panel is, quoting nothing.
        if ($data === 'tl:set:faq') {
            $this->answer($id, '');
            $this->sendFaq(self::placeOf($message));

            return;
        }

        if (preg_match('/^tl:set:(about|hours|mention)(?::(edit|reset))?$/', $data, $s) === 1) {
            $this->settingButton($s[1], $s[2] ?? '', $id, $message, $from);

            return;
        }

        if (preg_match('/^tl:set:assistant(?::(on|off|always|off_hours|reset))?$/', $data, $s) === 1) {
            $this->assistantSetting($s[1] ?? '', $id, $message, $from);

            return;
        }

        if ($data === 'tl:set:report') {
            $this->answer($id, '');
            $this->replyTo(self::placeOf($message), $this->report->html(now()->subDays(7), now()));

            return;
        }

        if (preg_match('/^tl:(claim|close|menu|back|tr|cmd|ai):(\d+)(?::([a-z0-9_-]+):([cp]))?$/', $data, $m) !== 1) {
            return;
        }

        $conversation = Conversation::query()->find((int) $m[2]);
        if ($conversation === null) {
            $this->answer($id, 'This conversation no longer exists.');

            return;
        }

        $argument = $m[3] ?? '';
        $where = ($m[4] ?? '') === ActionPanel::ON_PANEL ? ActionPanel::ON_PANEL : ActionPanel::ON_CARD;

        if ($m[1] === 'menu' || $m[1] === 'back') {
            $this->setKeyboard($message, $m[1] === 'menu'
                ? $this->panel->keyboard($conversation, ActionPanel::ON_CARD)
                : VisitorCard::keyboard($conversation));
            $this->answer($id, '');

            return;
        }

        if ($m[1] === 'tr') {
            $this->translationButton($conversation, $id, $message, $argument, $where);

            return;
        }

        if ($m[1] === 'cmd') {
            $this->commandButton($conversation, $id, $argument);

            return;
        }

        if ($m[1] === 'ai') {
            $this->assistantButton($conversation, $id, $message, $from, $argument, $where);

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
        if ($conversation->withAssistant()) {
            ConversationActions::handOff($conversation, $this->name($from).' took over.', byManager: true);
        }
        SyncTopic::dispatch($conversation->id);
        RefreshDashboard::dispatch();
        $this->answer($id, 'It’s yours.');
    }

    /**
     * /ai on: the assistant answers this conversation (at once, when the
     * visitor wrote last). /ai off: the managers do. /ai: the other way round.
     *
     * @param  array<mixed>  $from
     * @return string what the topic is told
     */
    private function switchAssistant(Conversation $conversation, string $to, array $from): string
    {
        $to = $to === '' ? ($conversation->withAssistant() ? 'off' : 'on') : $to;

        if (! in_array($to, ['on', 'off'], true)) {
            return 'Use /ai on or /ai off.';
        }

        if ($to === 'off') {
            return ConversationActions::handOff($conversation, $this->name($from).' switched the assistant off.', byManager: true)
                ? '🙋 The assistant is off here: you answer this conversation.'
                : 'The managers answer this conversation already.';
        }

        if (! $this->assistant->enabled()) {
            return $this->assistant->switchedOn()
                ? 'The assistant is not set up: THREADLIVE_GEMINI_KEY is empty.'
                : 'The assistant is off for the whole group: ⚙️ Settings → 🤖 Assistant.';
        }

        if ($conversation->withAssistant()) {
            return 'The assistant answers this conversation already.';
        }

        ConversationActions::backToAssistant($conversation);

        return '🤖 The assistant answers this conversation again. Any reply of yours here takes it back.';
    }

    /**
     * 🤖 AI answers / 🙋 Managers answer in the menu: /ai on|off, told in
     * the topic, and the ticks move.
     *
     * @param  array<mixed>  $message  the card or panel the button is on
     * @param  array<mixed>  $from
     * @param  ActionPanel::ON_*  $where
     */
    private function assistantButton(Conversation $conversation, string $callbackId, array $message, array $from, string $argument, string $where): void
    {
        $this->answer($callbackId, '');

        $topic = $this->topicOf($conversation);
        $told = $this->switchAssistant($conversation, $argument, $from);
        if ($topic !== null) {
            $this->replyTo($topic, $told);
        }

        $this->setKeyboard($message, $this->panel->keyboard($conversation->refresh(), $where));
    }

    /**
     * @param  array<mixed>  $message
     */
    private function dropTakeButton(Conversation $conversation, array $message): void
    {
        $this->setKeyboard($message, VisitorCard::keyboard($conversation));
    }

    /**
     * 🌐 EN / 🚫 Off in the menu: the same as /translate en|off — the topic
     * gets the same post — and the ticks move.
     *
     * @param  array<mixed>  $message  the card or panel the button is on
     * @param  ActionPanel::ON_*  $where
     */
    private function translationButton(Conversation $conversation, string $callbackId, array $message, string $argument, string $where): void
    {
        $topic = $this->topicOf($conversation);

        if (! $this->translation->enabled() || $topic === null) {
            $this->answer($callbackId, $topic === null ? 'This conversation has no topic.' : 'Translation is not set up.');

            return;
        }

        $current = $conversation->translate_to;
        if ($argument === ($current ?? 'off')) {
            $this->answer($callbackId, $current === null ? 'Translation is already off.' : 'Already translating into '.Languages::label($current).'.');

            return;
        }

        // Answered first: a translation of the last message can take a second.
        $this->answer($callbackId, $argument === 'off' ? 'Translation off.' : 'Translation into '.Languages::label($argument).'.');
        $this->toggleTranslation($conversation, $topic, $argument);
        $this->setKeyboard($message, $this->panel->keyboard($conversation->refresh(), $where));
    }

    /**
     * /order in the menu: the bot asks for the argument in the topic, as for
     * a bare "/order".
     */
    private function commandButton(Conversation $conversation, string $callbackId, string $name): void
    {
        try {
            $command = $this->commands->all()[$name] ?? null;
        } catch (\InvalidArgumentException $e) {
            report($e);
            $command = null;
        }

        $topic = $this->topicOf($conversation);

        if ($command === null || $topic === null) {
            $this->answer($callbackId, $command === null ? 'This command is no longer set up.' : 'This conversation has no topic.');

            return;
        }

        $this->answer($callbackId, '');
        // Not selective: the question is no reply to anyone's message, and
        // any manager may answer it.
        $this->askFor($topic, $name, $command->description(), selective: false);
    }

    /**
     * /menu: the buttons at the bottom of the topic, where the card has long
     * scrolled away. The /menu message and the previous panel are removed,
     * so the topic keeps one.
     *
     * @param  array<mixed>  $message
     */
    private function postPanel(Conversation $conversation, array $message): void
    {
        if (is_int($message['message_id'] ?? null)) {
            $this->deleteMessage($message['message_id']);
        }

        $previous = $this->cache->pull(self::PANEL_KEY.$conversation->id);
        if (is_int($previous)) {
            $this->deleteMessage($previous);
        }

        $panel = $this->replyTo(
            ['message_thread_id' => $message['message_thread_id'] ?? null, 'is_topic_message' => true],
            '⚙️ <b>#'.$conversation->id.' · '.e($conversation->name ?: 'Guest').'</b>',
            ['reply_markup' => ['inline_keyboard' => $this->panel->keyboard($conversation, ActionPanel::ON_PANEL)]],
        );

        if ($panel !== null) {
            $this->cache->put(self::PANEL_KEY.$conversation->id, $panel, now()->addDays(30));
        }
    }

    /**
     * /settings: in the "⚙️ Settings" topic, the panel posted afresh at its
     * bottom; anywhere else, a button to that topic (made when there is
     * none). The /settings message itself is removed.
     *
     * @param  array<mixed>  $message
     */
    private function postSettings(array $message): void
    {
        $place = self::placeOf($message);

        if (is_int($message['message_id'] ?? null)) {
            $this->deleteMessage($message['message_id']);
        }

        try {
            if ($this->settingsTopic->isIt($place['message_thread_id'])) {
                $this->settingsTopic->postPanel((int) $place['message_thread_id']);

                return;
            }

            $this->settingsTopic->ensure();
        } catch (TelegramException $e) {
            report($e);
            $this->replyTo($place, '⚙️ The settings topic could not be made: '.e($e->getMessage()).'. Run php artisan threadlive:setup.');

            return;
        }

        $link = $this->settingsTopic->link();
        $this->replyTo($place, '⚙️ The settings have their own topic, “'.SettingsTopic::NAME.'”.', $link === null ? [] : [
            'reply_markup' => ['inline_keyboard' => [[['text' => '⚙️ Open settings', 'url' => $link]]]],
        ]);
    }

    /**
     * 🏢 Company / 🕘 Hours / 👥 Mentions in /settings: the value shown to
     * anyone; ✏️ Change (asks for the new one) and ↩️ the config's — the
     * group's admins only.
     *
     * @param  'about'|'hours'|'mention'  $setting
     * @param  array<mixed>  $message  the panel the button is on
     * @param  array<mixed>  $from
     */
    private function settingButton(string $setting, string $action, string $callbackId, array $message, array $from): void
    {
        $place = self::placeOf($message);

        if ($action === '') {
            $this->answer($callbackId, '');
            $this->showSetting($setting, $place);

            return;
        }

        if (! $this->isAdmin($from)) {
            $this->answer($callbackId, 'Only the group\'s admins can change this.');

            return;
        }

        $this->answer($callbackId, '');

        if ($action === 'reset') {
            // The keys are the settings' names.
            GroupSettings::forget($setting);
            if ($setting === 'hours') {
                RefreshDashboard::dispatch();
            }
            $this->replyTo($place, match ($setting) {
                'about' => '↩️ The company text is the config\'s again (assistant.about).',
                'hours' => '↩️ The support hours are the config\'s again: '.e(WorkingHours::fromConfig()->label()).'.',
                'mention' => '↩️ The managers called by name are the config\'s again: '.self::mentionsLabel().'.',
            });

            return;
        }

        $prompt = $this->replyTo($place, match ($setting) {
            'about' => '✏️ Write the new company text here — who you are and what you do, for the AI assistant (up to '
                .GroupSettings::MAX_ABOUT.' characters, any language).',
            'hours' => '✏️ Write the new support hours here, e.g. <code>09:00-18:00 Europe/Kyiv</code>, or <code>24/7</code>.',
            'mention' => '✏️ Write the Telegram usernames of the managers to call, e.g. <code>@maria @oleh</code> — or <code>none</code>.',
        }, [
            'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => match ($setting) {
                'about' => 'We are …',
                'hours' => '09:00-18:00 Europe/Kyiv',
                'mention' => '@maria @oleh',
            }],
        ]);

        if ($prompt !== null && is_int($from['id'] ?? null)) {
            $waiting = ['setting' => $setting, 'prompt' => $prompt];
            $this->cache->put(self::SETTING_PROMPT_KEY.$prompt, $waiting, self::PROMPT_TTL);
            $this->cache->put(self::settingWaitKey($from['id'], $place), $waiting, now()->addMinutes(self::SETTING_WAIT_MINUTES));
        }
    }

    /**
     * The setting a message answers: it replies to the "send the new …"
     * question, or it is the next message of the admin who asked, in the
     * same place, within SETTING_WAIT_MINUTES. Never a command.
     *
     * @param  array<mixed>  $message
     * @return array{setting: 'about'|'hours'|'mention', prompt: int}|null
     */
    private function settingWaitingFor(array $message): ?array
    {
        $text = $message['text'] ?? null;
        if (! is_string($text) || str_starts_with(trim($text), '/')) {
            return null;
        }

        $repliedTo = $message['reply_to_message']['message_id'] ?? null;
        $waiting = is_int($repliedTo) ? $this->cache->get(self::SETTING_PROMPT_KEY.$repliedTo) : null;

        $from = $message['from']['id'] ?? null;
        if (! is_array($waiting) && is_int($from)) {
            $waiting = $this->cache->get(self::settingWaitKey($from, self::placeOf($message)));
        }

        return is_array($waiting) && in_array($waiting['setting'] ?? null, ['about', 'hours', 'mention'], true) && is_int($waiting['prompt'] ?? null)
            ? ['setting' => $waiting['setting'], 'prompt' => $waiting['prompt']]
            : null;
    }

    /**
     * The value is saved: the question is answered, the admin no longer
     * awaited.
     *
     * @param  array<mixed>  $message
     */
    private function settingDone(int $prompt, array $message): void
    {
        $this->cache->forget(self::SETTING_PROMPT_KEY.$prompt);

        if (is_int($message['from']['id'] ?? null)) {
            $this->cache->forget(self::settingWaitKey($message['from']['id'], self::placeOf($message)));
        }
    }

    /**
     * @param  array{message_thread_id: int|null, is_topic_message: bool}  $place
     */
    private static function settingWaitKey(int $userId, array $place): string
    {
        return self::SETTING_WAIT_KEY.$userId.':'.($place['message_thread_id'] ?? 0);
    }

    /**
     * @param  'about'|'hours'|'mention'  $setting
     * @param  array<mixed>  $place
     */
    private function showSetting(string $setting, array $place): void
    {
        if ($setting === 'mention') {
            $html = '👥 <b>Mentions</b> — called by name when a conversation nobody has needs a manager (the AI assistant\'s handoff, a waiting alert): '
                .self::mentionsLabel()
                .(GroupSettings::mentions() !== null ? ' (set here).' : ' (from the config, mention).');
            $overridden = GroupSettings::mentions() !== null;
        } elseif ($setting === 'about') {
            $about = $this->assistant->about();
            if (GroupSettings::about() === null) {
                // The config's is wrapped like code: one paragraph per run of lines.
                $about = (string) preg_replace('/(?<!\n)\n(?!\n)/', ' ', $about);
            }
            $html = '🏢 <b>Company</b> — what the AI assistant knows about you'
                .(GroupSettings::about() !== null ? ' (set here)' : ' (from the config, assistant.about)').":\n\n"
                .($about !== '' ? e(Str::limit($about, 3500)) : '<i>(empty)</i>');
            $overridden = GroupSettings::about() !== null;
        } else {
            $hours = WorkingHours::fromConfig();
            $html = '🕘 <b>Support hours</b>: '.e($hours->label())
                .(GroupSettings::hours() !== null ? ' (set here)' : ' (from the config)')
                .'. '.($hours->isOpen(now()) ? 'Open now.' : 'Closed now.');
            $overridden = GroupSettings::hours() !== null;
        }

        $buttons = [['text' => '✏️ Change', 'callback_data' => "tl:set:{$setting}:edit"]];
        if ($overridden) {
            $buttons[] = ['text' => '↩️ The config\'s', 'callback_data' => "tl:set:{$setting}:reset"];
        }

        $this->replyTo($place, $html, ['reply_markup' => ['inline_keyboard' => [$buttons]]]);
    }

    /**
     * The reply to "send the new …": checked, then saved — by an admin.
     *
     * @param  array<mixed>  $message
     */
    private function saveSetting(string $setting, int $prompt, array $message): void
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';

        if (! $this->isAdmin($from)) {
            $this->replyTo($message, 'Only the group\'s admins can change this.');

            return;
        }

        if ($setting === 'mention') {
            $parsed = Mentions::parse($text);
            if ($parsed['wrong'] !== [] || ($parsed['names'] === [] && $text === '')) {
                $this->replyTo($message, '❌ '.($parsed['wrong'] !== [] ? 'Not usernames: '.e(implode(', ', $parsed['wrong'])).'. ' : '')
                    .'Write e.g. <code>@maria @oleh</code>, or <code>none</code>.');

                return;
            }

            GroupSettings::set(GroupSettings::MENTION, $parsed['names']);
            $this->settingDone($prompt, $message);
            $this->replyTo($message, $parsed['names'] === []
                ? '✅ Nobody is called by name now.'
                : '✅ Called by name from now on: '.e(implode(' ', $parsed['names'])).'.');

            return;
        }

        if ($setting === 'about') {
            if ($text === '' || mb_strlen($text) > GroupSettings::MAX_ABOUT) {
                $this->replyTo($message, '❌ Send the company text as a message of up to '.GroupSettings::MAX_ABOUT.' characters.');

                return;
            }

            GroupSettings::set(GroupSettings::ABOUT, $text);
            $this->settingDone($prompt, $message);
            $this->replyTo($message, '✅ Company text saved: the AI assistant uses it from its next answer.');

            return;
        }

        $hours = WorkingHours::parse($text, WorkingHours::fromConfig()->tz);
        if ($hours === null) {
            $this->replyTo($message, '❌ Not understood. Send e.g. <code>09:00-18:00 Europe/Kyiv</code> (the zone may be left out), or <code>24/7</code>.');

            return;
        }

        GroupSettings::set(GroupSettings::HOURS, $hours->toArray());
        $this->settingDone($prompt, $message);
        RefreshDashboard::dispatch();
        $this->replyTo($message, '✅ Support hours: '.e($hours->label()).'. The widget, the assistant and the alerts follow them now.');
    }

    /** "@maria @oleh", or "nobody". */
    private static function mentionsLabel(): string
    {
        $mentions = Mentions::list();

        return $mentions === [] ? 'nobody' : e(implode(' ', $mentions));
    }

    /**
     * 🤖 Assistant in /settings: on or off, and when it answers — shown to
     * anyone, switched by the group's admins with the buttons under it,
     * which change the same post in place.
     *
     * @param  ''|'on'|'off'|'always'|'off_hours'|'reset'  $action
     * @param  array<mixed>  $message  the post the button is on
     * @param  array<mixed>  $from
     */
    private function assistantSetting(string $action, string $callbackId, array $message, array $from): void
    {
        if ($action === '') {
            $this->answer($callbackId, '');
            [$html, $keyboard] = $this->assistantStatus();
            $this->replyTo(self::placeOf($message), $html, ['reply_markup' => ['inline_keyboard' => $keyboard]]);

            return;
        }

        if (! $this->isAdmin($from)) {
            $this->answer($callbackId, 'Only the group\'s admins can change this.');

            return;
        }

        if ($action === 'on' && $this->assistant->driver() === null) {
            $this->answer($callbackId, 'The assistant is not set up: THREADLIVE_GEMINI_KEY is empty.');

            return;
        }

        match ($action) {
            'on', 'off' => GroupSettings::setAssistant('enabled', $action === 'on'),
            'always', 'off_hours' => GroupSettings::setAssistant('first', $action),
            'reset' => GroupSettings::forget(GroupSettings::ASSISTANT),
        };

        $this->answer($callbackId, match ($action) {
            'on' => 'The assistant is on.',
            'off' => 'The assistant is off: new conversations go to the managers.',
            'always' => 'It answers every new conversation first.',
            'off_hours' => 'It answers only outside the support hours.',
            'reset' => 'As the config says again.',
        });

        [$html, $keyboard] = $this->assistantStatus();
        $this->editMessage($message, $html, $keyboard);
        RefreshDashboard::dispatch();
    }

    /**
     * @return array{string, list<list<array{text: string, callback_data: string}>>}
     */
    private function assistantStatus(): array
    {
        $on = $this->assistant->switchedOn();
        $offHours = $this->assistant->first() === 'off_hours';
        $own = GroupSettings::get(GroupSettings::ASSISTANT) !== null;

        $html = '🤖 <b>AI assistant</b>: '.($on ? 'on' : 'off')
            .($on ? ' — it answers '.($offHours
                ? 'new conversations only outside the support hours ('.e(WorkingHours::fromConfig()->label()).')'
                : 'every new conversation first').', and hands it to you when it cannot help' : '')
            .($own ? ' (set here).' : ' (from the config).');

        if ($on && $this->assistant->driver() === null) {
            $html .= "\n⚠️ Not set up: THREADLIVE_GEMINI_KEY is empty, so the managers answer everything.";
        }

        $keyboard = [[
            $on
                ? ['text' => '⏸ Switch off', 'callback_data' => 'tl:set:assistant:off']
                : ['text' => '▶️ Switch on', 'callback_data' => 'tl:set:assistant:on'],
            $offHours
                ? ['text' => '🕐 Always', 'callback_data' => 'tl:set:assistant:always']
                : ['text' => '🌙 Only outside the hours', 'callback_data' => 'tl:set:assistant:off_hours'],
        ]];

        if ($own) {
            $keyboard[] = [['text' => '↩️ The config\'s', 'callback_data' => 'tl:set:assistant:reset']];
        }

        return [$html, $keyboard];
    }

    /**
     * A post of the bot's changed in place: its text and its buttons.
     *
     * @param  array<mixed>  $message
     * @param  list<list<array<string, string>>>  $keyboard
     */
    private function editMessage(array $message, string $html, array $keyboard): void
    {
        if (! is_int($message['message_id'] ?? null)) {
            return;
        }

        try {
            $this->telegram->call('editMessageText', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_id' => $message['message_id'],
                'text' => $html,
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ]);
        } catch (TelegramException $e) {
            if (! $e->isNotModified()) {
                report($e);
            }
        }
    }

    /**
     * An admin (or the creator) of the group, as Telegram says — asked again
     * after five minutes, so a demotion counts soon.
     *
     * @param  array<mixed>  $from
     */
    private function isAdmin(array $from): bool
    {
        $id = $from['id'] ?? null;
        if (! is_int($id)) {
            return false;
        }

        $cached = $this->cache->get(self::ADMIN_KEY.$id);
        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $member = $this->telegram->call('getChatMember', ['chat_id' => (string) config('threadlive.chat_id'), 'user_id' => $id]);
        } catch (TelegramException $e) {
            report($e);

            return false;
        }

        $admin = is_array($member) && in_array($member['status'] ?? null, ['creator', 'administrator'], true);
        $this->cache->put(self::ADMIN_KEY.$id, $admin, now()->addMinutes(5));

        return $admin;
    }

    /**
     * Where a message is — its topic, or General — without the message
     * itself: what replyTo() takes to post there quoting nothing.
     *
     * @param  array<mixed>  $message
     * @return array{message_thread_id: int|null, is_topic_message: bool}
     */
    private static function placeOf(array $message): array
    {
        $inTopic = is_int($message['message_thread_id'] ?? null) && ($message['is_topic_message'] ?? false) === true;

        return ['message_thread_id' => $inTopic ? $message['message_thread_id'] : null, 'is_topic_message' => $inTopic];
    }

    /** "ThisBot" in "/x@ThisBot": this bot's username, asked of Telegram once. */
    private function isOurBot(string $username): bool
    {
        $ours = $this->cache->get(self::USERNAME_KEY);

        if (! is_string($ours)) {
            try {
                $me = $this->telegram->call('getMe');
                $ours = is_array($me) && is_string($me['username'] ?? null) ? $me['username'] : '';
                $this->cache->put(self::USERNAME_KEY, $ours, now()->addDay());
            } catch (TelegramException $e) {
                report($e);

                return false;
            }
        }

        return $ours !== '' && strcasecmp($ours, $username) === 0;
    }

    /**
     * /faq: the current faq.json, to edit and send back. A faq.json sent with
     * the caption /faq: checked whole, then put in place of the FAQ — or, with
     * anything wrong, the FAQ stays as it was and the errors are listed.
     *
     * @param  array<mixed>  $message
     */
    private function faq(array $message): void
    {
        $document = is_array($message['document'] ?? null) ? $message['document'] : null;

        if ($document === null) {
            $this->sendFaq($message);

            return;
        }

        if (! is_string($document['file_id'] ?? null) || (is_int($document['file_size'] ?? null) && $document['file_size'] > Faq::MAX_BYTES)) {
            $this->replyTo($message, '❌ The FAQ was not changed: send a faq.json of up to 1 MB.');

            return;
        }

        try {
            $json = $this->telegram->download($document['file_id'])['contents'];
        } catch (TelegramException $e) {
            report($e);
            $this->replyTo($message, '❌ The FAQ was not changed: the bot could not fetch the file. Send it again.');

            return;
        }

        [$entries, $errors] = Faq::parse($json);

        if ($errors !== []) {
            $this->replyTo($message, "❌ The FAQ was not changed:\n".implode("\n", array_map(fn (string $error) => '• '.e($error), $errors)));

            return;
        }

        Faq::save($entries);

        $lines = ['✅ FAQ updated: '.e(Faq::summary($entries)).'.'];
        if (Faq::translateTo() !== [] && $this->translation->enabled()) {
            $lines[] = '🌐 Translating what is missing ('.e(implode(', ', Faq::translateTo())).') — the result comes here.';
            $thread = is_int($message['message_thread_id'] ?? null) && ($message['is_topic_message'] ?? false) === true
                ? $message['message_thread_id']
                : 0;
            TranslateFaq::dispatch($thread);
        }

        $this->replyTo($message, implode("\n", $lines));
    }

    /**
     * @param  array<mixed>  $message
     */
    private function sendFaq(array $message): void
    {
        $entries = Faq::entries();

        if ($entries === []) {
            $this->replyTo($message, "📚 No FAQ yet. Send a faq.json with the caption /faq:\n"
                .'<code>'.e('[{"id": "invoice", "question": "Where is my invoice?", "answer": "Account → Invoices."}]').'</code>'
                ."\nSeveral languages: <code>".e('"question": {"en": "…", "uk": "…"}').'</code> — the same for "answer".');

            return;
        }

        $inTopic = is_int($message['message_thread_id'] ?? null) && ($message['is_topic_message'] ?? false) === true;

        try {
            $this->telegram->upload('sendDocument', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_thread_id' => $inTopic ? $message['message_thread_id'] : null,
                'caption' => '📚 '.Faq::summary($entries).'. Edit it and send it back with the caption /faq.',
                'disable_notification' => true,
                'reply_parameters' => is_int($message['message_id'] ?? null)
                    ? ['message_id' => $message['message_id'], 'allow_sending_without_reply' => true]
                    : null,
            ], ['document', Faq::encode($entries), 'faq.json']);
        } catch (TelegramException $e) {
            report($e);
        }
    }

    /**
     * Where to post about a conversation from a button: its topic, as
     * replyTo() takes it. Null when it has none.
     *
     * @return array<string, mixed>|null
     */
    private function topicOf(Conversation $conversation): ?array
    {
        return $conversation->telegram_thread_id === null
            ? null
            : ['message_thread_id' => $conversation->telegram_thread_id, 'is_topic_message' => true];
    }

    /**
     * New buttons under a message of the bot's. "Not modified" (pressed
     * twice) is no error.
     *
     * @param  array<mixed>  $message
     * @param  list<list<array{text: string, callback_data: string}>>  $keyboard
     */
    private function setKeyboard(array $message, array $keyboard): void
    {
        if (! is_int($message['message_id'] ?? null)) {
            return;
        }

        try {
            $this->telegram->call('editMessageReplyMarkup', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_id' => $message['message_id'],
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ]);
        } catch (TelegramException $e) {
            if (! $e->isNotModified()) {
                report($e);
            }
        }
    }

    /** The callback answered: a toast with $text, or just the spinner stopped. */
    private function answer(string $callbackId, string $text): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', array_filter(
                ['callback_query_id' => $callbackId, 'text' => $text],
                fn (string $value) => $value !== '',
            ));
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
            // "/start@SomeOtherBot" is someone else's; a bare one is ours,
            // and so is "/x@ThisBot" (the "/" menu adds the bot's name).
            if (($m[2] ?? '') === '' || $this->isOurBot(substr($m[2], 1))) {
                // What works here: the package's commands for anywhere, then /find and the app's.
                $works = ['settings', 'faq', ...array_keys($commands)];
                $this->replyTo($message, 'Unknown command /'.e($name).'. Try '.implode(', ', array_map(fn (string $c) => '/'.$c, $works)).'.');
            }

            return true;
        }

        $argument = trim($m[3] ?? '');

        if ($argument === '') {
            $this->askFor($message, $name, $commands[$name], selective: true);

            return true;
        }

        $this->answerCommand($message, $all[$name], $argument);

        return true;
    }

    /**
     * "🔎 Look up an order — send it in reply to this message." The reply
     * runs the command (managerCommand() finds it by the question's id).
     *
     * @param  array<mixed>  $message
     */
    private function askFor(array $message, string $name, string $description, bool $selective): void
    {
        $prompt = $this->replyTo($message, '🔎 '.e($description).' — send it in reply to this message.', [
            'reply_markup' => [
                'force_reply' => true,
                'selective' => $selective,
                'input_field_placeholder' => mb_substr($description, 0, 64),
            ],
        ]);

        if ($prompt !== null) {
            $this->cache->put(self::PROMPT_KEY.$prompt, $name, self::PROMPT_TTL);
        }
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
     * A silent reply in the same topic (or General). A $message without a
     * message_id (a topic, from topicOf()) gets a plain post there.
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
                'reply_parameters' => is_int($message['message_id'] ?? null)
                    ? ['message_id' => $message['message_id'], 'allow_sending_without_reply' => true]
                    : null,
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
        if (is_int($message['message_id'] ?? null)) {
            $this->deleteMessage($message['message_id']);
        }
    }

    private function deleteMessage(int $messageId): void
    {
        try {
            $this->telegram->call('deleteMessage', [
                'chat_id' => (string) config('threadlive.chat_id'),
                'message_id' => $messageId,
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
