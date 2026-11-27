<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Threadlive\Support\GroupSettings;

/**
 * "⚙️ Settings": the group's own topic for its settings — the panel
 * (📚 FAQ · 🏢 Company · 🕘 Hours · 🤖 Assistant · 👥 Mentions · 📊 Report)
 * pinned there, and whatever an admin
 * sets. Closed, so that only the group's admins write in it; anyone sees it.
 * threadlive:setup makes it (again, when it was deleted); /settings
 * elsewhere points to it. Its id is kept with the group's settings.
 */
final class SettingsTopic
{
    public const NAME = '⚙️ Settings';

    public const PANEL = [
        [
            ['text' => '📚 FAQ', 'callback_data' => 'tl:set:faq'],
            ['text' => '🏢 Company', 'callback_data' => 'tl:set:about'],
            ['text' => '🕘 Hours', 'callback_data' => 'tl:set:hours'],
        ],
        [
            ['text' => '🤖 Assistant', 'callback_data' => 'tl:set:assistant'],
            ['text' => '👥 Mentions', 'callback_data' => 'tl:set:mention'],
            ['text' => '📊 Report', 'callback_data' => 'tl:set:report'],
        ],
    ];

    private const THREAD = '_settings_topic';

    private const PANEL_MESSAGE = '_settings_panel';

    public function __construct(private readonly TelegramClient $telegram) {}

    public function thread(): ?int
    {
        $thread = GroupSettings::get(self::THREAD);

        return is_int($thread) ? $thread : null;
    }

    public function isIt(mixed $thread): bool
    {
        return is_int($thread) && $thread === $this->thread();
    }

    /** https://t.me/c/<group id without -100>/<topic> — opens it. */
    public function link(): ?string
    {
        $chatId = (string) config('threadlive.chat_id');
        $thread = $this->thread();

        return $thread === null || ! str_starts_with($chatId, '-100') ? null : 'https://t.me/c/'.substr($chatId, 4).'/'.$thread;
    }

    /**
     * The topic, made when there is none (or it was deleted), with the panel
     * posted afresh and pinned.
     *
     * @return int the topic's id
     *
     * @throws TelegramException
     */
    public function ensure(): int
    {
        $thread = $this->thread();

        if ($thread !== null) {
            try {
                $this->postPanel($thread);

                return $thread;
            } catch (TelegramException $e) {
                if (! $e->isMissingTopic()) {
                    throw $e;
                }
            }
        }

        $topic = $this->telegram->call('createForumTopic', ['chat_id' => $this->chatId(), 'name' => self::NAME]);
        $thread = is_array($topic) && is_int($topic['message_thread_id'] ?? null) ? $topic['message_thread_id'] : 0;
        GroupSettings::set(self::THREAD, $thread);
        GroupSettings::forget(self::PANEL_MESSAGE);
        $this->postPanel($thread);

        return $thread;
    }

    /**
     * The panel posted again at the bottom of the topic — the previous one
     * removed — pinned, and the topic closed again.
     *
     * @throws TelegramException
     */
    public function postPanel(int $thread): void
    {
        // Closed, a topic takes posts from admins only; reopened for a
        // moment, it takes the bot's whatever its rights.
        $this->quietly('reopenForumTopic', ['message_thread_id' => $thread]);

        $id = $this->telegram->sendMessage(
            $this->chatId(),
            '⚙️ <b>Settings</b> — anyone sees them; only the group\'s admins change them.',
            $thread,
            silent: true,
            keyboard: self::PANEL,
        );

        $previous = GroupSettings::get(self::PANEL_MESSAGE);
        if (is_int($previous)) {
            $this->quietly('deleteMessage', ['message_id' => $previous]);
        }
        GroupSettings::set(self::PANEL_MESSAGE, $id);

        $this->quietly('pinChatMessage', ['message_id' => $id, 'disable_notification' => true]);
        $this->quietly('closeForumTopic', ['message_thread_id' => $thread]);
    }

    private function chatId(): string
    {
        return (string) config('threadlive.chat_id');
    }

    /**
     * A call whose failure changes nothing that matters: already open or
     * closed, a panel deleted by hand, no right to pin.
     *
     * @param  array<string, mixed>  $params
     */
    private function quietly(string $method, array $params): void
    {
        try {
            $this->telegram->call($method, ['chat_id' => $this->chatId(), ...$params]);
        } catch (TelegramException $e) {
            if ($e->isMissingTopic()) {
                throw $e;
            }
        }
    }
}
