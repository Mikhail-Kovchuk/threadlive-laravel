<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Threadlive\Enums\Direction;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Support\WorkingHours;

/**
 * "📊 Report" for a period: conversations, what the assistant did, how fast
 * the managers answered and who did, the visitors' ratings. Posted in
 * General every week (threadlive:report, scheduled) and on ⚙️ Settings →
 * 📊 Report.
 */
final class Report
{
    /** The 👎 with a comment shown under the counts. */
    private const BAD_COMMENTS = 3;

    private const MANAGERS = 10;

    /** From $from to $to, both included. */
    public function html(CarbonInterface $from, CarbonInterface $to): string
    {
        $tz = WorkingHours::fromConfig()->tz;
        $lines = ['📊 <b>Report</b> · '.e(self::period(Carbon::instance($from)->tz($tz), Carbon::instance($to)->tz($tz)))];

        $new = fn () => Conversation::query()->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        $started = $new()->count();
        $closed = Conversation::query()->where('closed_at', '>=', $from)->where('closed_at', '<=', $to)->count();

        $lines[] = '';
        $lines[] = "💬 Conversations: {$started} new · {$closed} closed";

        if ($started > 0) {
            $withAssistant = $new()->where(fn ($q) => $q->where('assistant_replies', '>', 0)->orWhereNotNull('handed_off_at'))->count();
            if ($withAssistant > 0) {
                $handed = $new()->whereNotNull('handed_off_at')->count();
                $lines[] = "🤖 Assistant: {$withAssistant} — ".($withAssistant - $handed).' without a manager, '.$handed.' passed on';
            }

            $unanswered = $new()->whereDoesntHave('messages', fn ($q) => $q->where('direction', Direction::Agent))->count();
            if ($unanswered > 0) {
                $lines[] = "⚠️ No reply at all: {$unanswered}";
            }

            $waits = $this->firstReplyWaits($from, $to);
            if ($waits !== []) {
                $lines[] = '⏱ A manager\'s first reply: '.self::duration(self::median($waits)).' typically (median of '.count($waits).')';
            }
        }

        $managers = $this->managers($from, $to);
        if ($managers !== []) {
            $lines[] = '👥 Answered: '.implode(' · ', array_map(
                fn (string $name, int $count) => e($name).' '.$count,
                array_keys($managers),
                $managers,
            ));
        }

        $lines = [...$lines, ...$this->ratings($from, $to)];

        return implode("\n", $lines);
    }

    /**
     * Seconds from the wait's start — the conversation's, or the assistant's
     * handoff — to a manager's first reply, for the conversations started in
     * the period that got one.
     *
     * @return list<int>
     */
    private function firstReplyWaits(CarbonInterface $from, CarbonInterface $to): array
    {
        $conversations = Conversation::query()
            ->where('created_at', '>=', $from)->where('created_at', '<=', $to)
            ->get(['id', 'created_at', 'handed_off_at'])
            ->keyBy('id');

        if ($conversations->isEmpty()) {
            return [];
        }

        $replies = Message::query()
            ->whereIn('conversation_id', $conversations->keys())
            ->where('direction', Direction::Agent)
            ->where('by_assistant', false)
            ->orderBy('id')
            ->get(['conversation_id', 'created_at'])
            ->groupBy('conversation_id');

        $waits = [];
        foreach ($conversations as $id => $conversation) {
            $start = $conversation->handed_off_at ?? $conversation->created_at;
            if ($start === null) {
                continue;
            }

            // A manager who took over from the assistant replied as it was handed off.
            $first = $replies->get($id)?->first(fn (Message $m) => $m->created_at !== null && $m->created_at->gte($start->copy()->subSeconds(5)));
            if ($first?->created_at !== null) {
                $waits[] = max(0, (int) $start->diffInSeconds($first->created_at, true));
            }
        }

        return $waits;
    }

    /**
     * Conversations each manager replied in during the period, most first.
     *
     * @return array<string, int>
     */
    private function managers(CarbonInterface $from, CarbonInterface $to): array
    {
        return Message::query()
            ->where('created_at', '>=', $from)->where('created_at', '<=', $to)
            ->where('direction', Direction::Agent)
            ->where('by_assistant', false)
            ->whereNotNull('agent_name')
            ->selectRaw('agent_name, count(distinct conversation_id) as conversations')
            ->groupBy('agent_name')
            ->orderByDesc('conversations')
            ->limit(self::MANAGERS)
            ->get()
            ->mapWithKeys(fn (Message $m) => [(string) $m->agent_name => (int) $m->getAttribute('conversations')])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function ratings(CarbonInterface $from, CarbonInterface $to): array
    {
        $rated = fn () => Conversation::query()->where('rated_at', '>=', $from)->where('rated_at', '<=', $to);
        $good = $rated()->where('rating', Conversation::GOOD)->count();
        $bad = $rated()->where('rating', Conversation::BAD)->count();

        if ($good + $bad === 0) {
            return [];
        }

        $lines = ["⭐ Ratings: 👍 {$good} · 👎 {$bad} (".(int) round($good * 100 / ($good + $bad)).'% good)'];

        $comments = $rated()
            ->where('rating', Conversation::BAD)
            ->whereNotNull('rating_comment')
            ->orderByDesc('rated_at')
            ->limit(self::BAD_COMMENTS)
            ->get();

        foreach ($comments as $conversation) {
            $link = Dashboard::topicLink($conversation);
            $who = '#'.$conversation->id.' '.e($conversation->name ?: 'Guest');
            $lines[] = '   👎 '.($link !== null ? '<a href="'.e($link).'">'.$who.'</a>' : $who)
                .': “'.e(Str::limit((string) $conversation->rating_comment, 200)).'”';
        }

        return $lines;
    }

    /** "20–26 Nov 2026", "28 Nov – 4 Dec 2026". */
    private static function period(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('j M Y');
        }

        return $from->isSameMonth($to)
            ? $from->format('j').'–'.$to->format('j M Y')
            : $from->format('j M').' – '.$to->format('j M Y');
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): int
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return 'under a minute';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);

        return $hours.' h'.($minutes % 60 > 0 ? ' '.($minutes % 60).' min' : '');
    }
}
