<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Support\Str;
use Threadlive\Jobs\DeliverVisitorMessages;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Translation\Languages;

/**
 * What the assistant does, shown in the topic: its replies, silent, under
 * 🤖 — the visitor's language quoted, as everywhere in the topic, with the
 * managers' version under it — and a handoff, with sound.
 */
final class AssistantPosts
{
    private const MAX = 3500;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly Topics $topics,
    ) {}

    /** @throws TelegramException */
    public function reply(Conversation $conversation, Message $message, string $forManagers, string $managersLanguage): void
    {
        $html = '🤖 <b>'.e((string) $message->agent_name).'</b>'."\n";
        $forManagers = trim($forManagers);

        if ($forManagers !== '' && $forManagers !== trim($message->body)) {
            $from = $conversation->visitor_language !== null ? Languages::label($conversation->visitor_language).' ' : '';
            $html .= DeliverVisitorMessages::quote(e(Str::limit($message->body, self::MAX)))
                .'🌐 <b>'.$from.'→ '.Languages::label($managersLanguage)."</b>\n"
                .e(Str::limit($forManagers, self::MAX));
        } else {
            $html .= e(Str::limit($message->body, self::MAX));
        }

        $thread = $this->topics->ensureOpen($conversation);
        $id = $this->telegram->sendMessage($this->topics->chatId(), $html, $thread, silent: true);
        $message->forceFill(['telegram_message_id' => $id ?: null])->save();
    }

    /**
     * "🙋 Needs a manager: why" and the assistant's summary, with sound, in
     * the topic — calling the manager who has the conversation by name.
     * Nobody has it: the same in General too, with a button to the topic and
     * the managers of 👥 Mentions (config mention) called by name, so it is
     * seen outside the topic list.
     *
     * @throws TelegramException
     */
    public function handoff(Conversation $conversation, string $summary): void
    {
        $why = '🙋 <b>Needs a manager</b>: '.e((string) $conversation->handoff_reason);
        $about = trim($summary) !== '' ? "\n📝 ".e(Str::limit(trim($summary), 1000)) : '';
        $assignee = $conversation->assignee_tg_id;

        $html = $why.$about;
        if ($assignee !== null) {
            $html .= "\n".Mentions::user($assignee, (string) ($conversation->assignee_name ?: 'Manager'));
        }

        $thread = $this->topics->ensureOpen($conversation);
        $this->telegram->sendMessage($this->topics->chatId(), $html, $thread, silent: false);

        if ($assignee !== null) {
            return;
        }

        $visitor = e($conversation->name ?: 'Guest').' · #'.$conversation->id;
        $mentions = Mentions::managers();
        $link = Dashboard::topicLink($conversation->refresh());

        // The topic has it already: a failure here is reported, never a
        // reason to post in the topic again.
        try {
            $this->telegram->call('sendMessage', [
                'chat_id' => $this->topics->chatId(),
                'text' => "🙋 <b>{$visitor}</b> needs a manager: ".e((string) $conversation->handoff_reason).$about
                    .($mentions !== '' ? "\n".$mentions : ''),
                'parse_mode' => 'HTML',
                'disable_notification' => false,
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => $link === null ? null : ['inline_keyboard' => [[['text' => '💬 Open the conversation', 'url' => $link]]]],
            ]);
        } catch (TelegramException $e) {
            report($e);
        }
    }
}
