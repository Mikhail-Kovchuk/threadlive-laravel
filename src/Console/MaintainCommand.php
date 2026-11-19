<?php

declare(strict_types=1);

namespace Threadlive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;
use Threadlive\Mail\UnreadReplies;
use Threadlive\Models\Conversation;
use Threadlive\Support\ConversationActions;
use Threadlive\Support\WorkingHours;
use Threadlive\Telegram\Dashboard;
use Threadlive\Telegram\TelegramClient;
use Threadlive\Telegram\TelegramException;
use Throwable;

/**
 * Every five minutes (the service provider schedules it):
 *
 *   - closes conversations idle for `close_after_hours`;
 *   - deletes topics closed for `delete_topic_after_days` (history stays here);
 *   - within support hours, posts "⏰ Anna is waiting 12 min" in General,
 *     with sound, once per wait, after `waiting_alert_minutes`;
 *   - e-mails replies not read in the widget after `email_unread_after_minutes`;
 *   - refreshes the dashboard.
 */
final class MaintainCommand extends Command
{
    protected $signature = 'threadlive:maintain';

    protected $description = 'Close idle conversations, delete old topics, alert about waiting visitors, refresh the dashboard';

    /** Telegram's group rate limit is shared with real messages: a few alerts per run. */
    private const ALERTS_PER_RUN = 5;

    /** The rest wait for the next run. */
    private const EMAILS_PER_RUN = 50;

    public function handle(TelegramClient $telegram, Dashboard $dashboard): int
    {
        if (! config('threadlive.enabled') || (string) config('threadlive.bot_token') === '' || (string) config('threadlive.chat_id') === '') {
            $this->components->info('Threadlive is off or not configured; nothing to do.');

            return self::SUCCESS;
        }

        $closed = $this->closeIdle();
        $deleted = $this->deleteOldTopics($telegram);
        $alerts = $this->waitingAlerts($telegram);
        $emails = $this->unreadEmails();
        $dashboard->refresh();

        $this->components->info("Closed {$closed}, deleted {$deleted} topics, sent {$alerts} waiting alerts and {$emails} e-mails.");

        return self::SUCCESS;
    }

    private function closeIdle(): int
    {
        $hours = (int) config('threadlive.close_after_hours', 24);
        if ($hours <= 0) {
            return 0;
        }

        $closed = 0;
        Conversation::query()
            ->where('status', '!=', ConversationStatus::Closed)
            ->where('last_activity_at', '<', now()->subHours($hours))
            ->each(function (Conversation $conversation) use (&$closed) {
                $closed += ConversationActions::close($conversation, 'auto') ? 1 : 0;
            });

        return $closed;
    }

    private function deleteOldTopics(TelegramClient $telegram): int
    {
        $days = (int) config('threadlive.delete_topic_after_days', 7);
        if ($days <= 0) {
            return 0;
        }

        $deleted = 0;
        Conversation::query()
            // Closed as a conversation too: a visitor who just came back is
            // having their topic reopened, not deleted.
            ->where('status', ConversationStatus::Closed)
            ->where('topic_state', TopicState::Closed)
            ->where('topic_closed_at', '<', now()->subDays($days))
            ->whereNotNull('telegram_thread_id')
            ->each(function (Conversation $conversation) use ($telegram, &$deleted) {
                try {
                    $telegram->call('deleteForumTopic', [
                        'chat_id' => (string) config('threadlive.chat_id'),
                        'message_thread_id' => $conversation->telegram_thread_id,
                    ]);
                } catch (TelegramException $e) {
                    if (! $e->isMissingTopic()) {
                        report($e);

                        return;
                    }
                }

                $conversation->forceFill(['topic_state' => TopicState::Deleted, 'topic_deleted_at' => now()])->save();
                $deleted++;
            });

        return $deleted;
    }

    private function waitingAlerts(TelegramClient $telegram): int
    {
        $minutes = (int) config('threadlive.waiting_alert_minutes', 10);
        if ($minutes <= 0 || ! WorkingHours::fromConfig()->isOpen(now())) {
            return 0;
        }

        $sent = 0;
        $waiting = Conversation::query()
            ->where('status', ConversationStatus::Waiting)
            ->whereNull('waiting_alert_sent_at')
            ->where('last_visitor_message_at', '<=', now()->subMinutes($minutes))
            ->orderBy('last_visitor_message_at')
            ->limit(self::ALERTS_PER_RUN)
            ->get();

        foreach ($waiting as $conversation) {
            $name = e($conversation->name ?: 'A guest');
            $link = Dashboard::topicLink($conversation);
            $who = $link ? '<a href="'.e($link).'">'.$name.'</a>' : $name;
            $age = Dashboard::age($conversation->last_visitor_message_at, now());

            try {
                // With sound: this is the one General post that should be heard.
                $telegram->sendMessage((string) config('threadlive.chat_id'), "⏰ {$who} is waiting {$age}", null, silent: false);
            } catch (TelegramException $e) {
                report($e);

                break;
            }

            $conversation->forceFill(['waiting_alert_sent_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * Replies not read in the widget for `email_unread_after_minutes` go to
     * the visitor's e-mail, once per batch: the next reply starts the clock
     * again (UpdateHandler clears unread_email_sent_at). Replies older than
     * a day are left alone — no flood of old ones on the first run.
     */
    private function unreadEmails(): int
    {
        $minutes = (int) config('threadlive.email_unread_after_minutes', 15);
        if ($minutes <= 0) {
            return 0;
        }

        $due = Conversation::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereNull('unread_email_sent_at')
            ->where('last_agent_message_at', '<=', now()->subMinutes($minutes))
            ->where('last_agent_message_at', '>=', now()->subDay())
            ->orderBy('last_agent_message_at')
            ->limit(self::EMAILS_PER_RUN)
            ->get();

        $sent = 0;
        foreach ($due as $conversation) {
            $replies = $conversation->messages()
                ->where('direction', Direction::Agent)
                ->where('id', '>', $conversation->visitor_read_message_id ?? 0)
                ->orderBy('id')
                ->get();

            // Marked first: a mailer that fails tries no more for this batch,
            // rather than every five minutes.
            $conversation->forceFill(['unread_email_sent_at' => now()])->save();

            if ($replies->isEmpty()) {
                continue; // read in the widget in time
            }

            try {
                Mail::to((string) $conversation->email)
                    ->locale(self::locale($conversation))
                    ->send(new UnreadReplies($conversation, $replies));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /** The visitor's language as far as we know it: what they write in, or their browser's. */
    private static function locale(Conversation $conversation): ?string
    {
        $locale = $conversation->visitor_language ?? $conversation->locale;

        return is_string($locale) && $locale !== '' ? strtolower(Str::before(str_replace('-', '_', $locale), '_')) : null;
    }
}
