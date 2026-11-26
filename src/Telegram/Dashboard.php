<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Delivery;
use Threadlive\Models\Conversation;
use Threadlive\Support\WorkingHours;

/**
 * One pinned message in General, edited in place:
 *
 *   ❗ Waiting for a reply (3): Anna · 12m, John · 4m
 *   💬 In progress (8): Maria — 5, Olesya — 3
 *   🤖 With the assistant (4)            (only when there are any)
 *   ✅ Closed today: 21
 *
 * Its id is kept in the cache; with the cache lost, a new one is posted.
 */
final class Dashboard
{
    private const CACHE_KEY = 'threadlive:dashboard:message_id';

    private const WAITING_SHOWN = 10;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly Cache $cache,
    ) {}

    /** @throws TelegramException */
    public function refresh(): void
    {
        $chatId = (string) config('threadlive.chat_id');
        $html = $this->html();
        $messageId = $this->cache->get(self::CACHE_KEY);

        if (is_int($messageId)) {
            try {
                $this->telegram->call('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $html,
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                ]);

                return;
            } catch (TelegramException $e) {
                if ($e->isNotModified()) {
                    return;
                }
                if (! str_contains(strtolower($e->getMessage()), 'message to edit not found')) {
                    throw $e;
                }
            }
        }

        $messageId = $this->telegram->sendMessage($chatId, $html, null, silent: true);

        try {
            $this->telegram->call('pinChatMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'disable_notification' => true,
            ]);
        } catch (TelegramException $e) {
            report($e); // Unpinned still works; setup reports missing rights.
        }

        $this->cache->forever(self::CACHE_KEY, $messageId);
    }

    public function html(): string
    {
        $tz = WorkingHours::fromConfig()->tz;
        $now = Carbon::now($tz);

        // Counted in the database; only the names shown are loaded. The
        // assistant's conversations are not the managers' to answer yet.
        $waitingCount = Conversation::query()->where('status', ConversationStatus::Waiting)->where('handler', Conversation::HUMAN)->count();
        $waiting = Conversation::query()
            ->where('status', ConversationStatus::Waiting)
            ->where('handler', Conversation::HUMAN)
            ->orderBy('last_visitor_message_at')
            ->limit(self::WAITING_SHOWN)
            ->get();

        $shown = $waiting->map(function (Conversation $c) use ($now) {
            $name = e($c->name ?: 'Guest');
            $link = self::topicLink($c);
            $label = $link ? '<a href="'.e($link).'">'.$name.'</a>' : $name;

            return $label.' · '.self::age(self::waitingSince($c), $now);
        })->implode(', ');

        $withAssistant = Conversation::query()
            ->where('status', '!=', ConversationStatus::Closed)
            ->where('handler', Conversation::AI)
            ->count();

        if ($waitingCount > self::WAITING_SHOWN) {
            $shown .= ', …';
        }

        /** @var array<string, int> $byAssignee */
        $byAssignee = Conversation::query()
            ->where('status', ConversationStatus::Answered)
            ->where('handler', Conversation::HUMAN)
            ->groupBy('assignee_name')
            ->selectRaw('assignee_name, count(*) as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) (($row->assignee_name ?? '') ?: 'Unassigned') => (int) ($row->total ?? 0)])
            ->all();
        ksort($byAssignee);
        $answered = array_sum($byAssignee);
        $inProgress = collect($byAssignee)->map(fn (int $count, string $name) => e($name).' — '.$count)->implode(', ');

        // Dates are stored in the application's time zone; the day starts in support's.
        $closedToday = Conversation::query()
            ->where('status', ConversationStatus::Closed)
            ->where('closed_at', '>=', $now->copy()->startOfDay()->setTimezone((string) config('app.timezone', 'UTC')))
            ->count();

        return implode("\n", array_filter([
            '📊 <b>Support</b> · updated '.$now->format('H:i'),
            '',
            "❗ Waiting for a reply ({$waitingCount})".($shown !== '' ? ': '.$shown : ''),
            "💬 In progress ({$answered})".($inProgress !== '' ? ': '.$inProgress : ''),
            $withAssistant > 0 ? "🤖 With the assistant ({$withAssistant})" : null,
            "✅ Closed today: {$closedToday}",
            $this->notDelivered(),
        ], fn (?string $line) => $line !== null));
    }

    /**
     * "⚠️ Not delivered (3): Anna — 2, John — 1": visitor messages the
     * delivery job gave up on. Only shown when there are any.
     */
    private function notDelivered(): ?string
    {
        $failed = Conversation::query()
            ->whereHas('messages', fn ($q) => $q->where('delivery', Delivery::Failed))
            ->withCount(['messages as failed_count' => fn ($q) => $q->where('delivery', Delivery::Failed)])
            ->orderBy('id')
            ->get();

        if ($failed->isEmpty()) {
            return null;
        }

        $total = (int) $failed->sum('failed_count');
        $shown = $failed->take(self::WAITING_SHOWN)->map(function (Conversation $c) {
            $name = e($c->name ?: 'Guest').' #'.$c->id;
            $link = self::topicLink($c);
            $label = $link ? '<a href="'.e($link).'">'.$name.'</a>' : $name;

            return $label.' — '.(int) $c->getAttribute('failed_count');
        })->implode(', ');

        if ($failed->count() > self::WAITING_SHOWN) {
            $shown .= ', …';
        }

        return "\n⚠️ <b>Not delivered ({$total})</b>: {$shown}\n"
            .'<i>Send them again: php artisan threadlive:retry-failed</i>';
    }

    /** https://t.me/c/<group id without -100>/<topic> — opens the topic. */
    public static function topicLink(Conversation $conversation): ?string
    {
        $chatId = (string) config('threadlive.chat_id');

        if ($conversation->telegram_thread_id === null || ! str_starts_with($chatId, '-100')) {
            return null;
        }

        return 'https://t.me/c/'.substr($chatId, 4).'/'.$conversation->telegram_thread_id;
    }

    /**
     * Since when the managers owe a reply: the visitor's last message, or
     * the assistant's handoff when that came later.
     */
    public static function waitingSince(Conversation $conversation): ?Carbon
    {
        $visitor = $conversation->last_visitor_message_at;
        $handoff = $conversation->handed_off_at;

        return $handoff !== null && ($visitor === null || $handoff->gt($visitor)) ? $handoff : $visitor;
    }

    public static function age(?Carbon $since, Carbon $now): string
    {
        if ($since === null) {
            return '?';
        }

        $minutes = max(0, (int) $since->diffInMinutes($now, true));

        return $minutes < 60 ? "{$minutes}m" : intdiv($minutes, 60).'h';
    }
}
