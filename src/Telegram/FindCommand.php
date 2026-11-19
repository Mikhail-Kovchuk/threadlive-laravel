<?php

declare(strict_types=1);

namespace Threadlive\Telegram;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Threadlive\CommandReply;
use Threadlive\Contracts\ManagerCommand;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Models\Conversation;
use Threadlive\Models\Message;
use Threadlive\Support\WorkingHours;

/**
 * The package's own /find, in any topic or General:
 *
 *   /find 12  or  /find #12      → that conversation
 *   /find anna@example.com       → by the visitor's e-mail
 *   /find Anna  /find refund     → by name, or by words in the messages
 *
 * Newest first; each hit links to its topic.
 */
final class FindCommand implements ManagerCommand
{
    private const SHOWN = 10;

    private const SNIPPET = 60;

    public function name(): string
    {
        return 'find';
    }

    public function description(): string
    {
        return 'Find a conversation: #number, e-mail, name or words from it';
    }

    public function handle(string $argument): ?CommandReply
    {
        $query = trim($argument);

        if (preg_match('/^#?(\d+)$/', $query, $m) === 1) {
            $conversation = Conversation::query()->find((int) $m[1]);

            return $conversation === null ? null : $this->reply("Conversation #{$conversation->id}", [$conversation], '');
        }

        if (mb_strlen($query) < 2) {
            return null;
        }

        $like = '%'.addcslashes($query, '\\%_').'%';
        $matchingMessage = fn (Builder $q) => $q
            ->whereIn('direction', [Direction::Visitor, Direction::Agent, Direction::Note])
            ->where('body', 'like', $like);

        $found = Conversation::query()
            ->where(function (Builder $q) use ($like, $query, $matchingMessage) {
                $q->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('user_ref', $query)
                    ->orWhereHas('messages', $matchingMessage);
            })
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit(self::SHOWN + 1)
            ->get();

        if ($found->isEmpty()) {
            return null;
        }

        $more = $found->count() > self::SHOWN;
        $shown = $found->take(self::SHOWN);
        $title = ($more ? 'The newest '.self::SHOWN : (string) $shown->count())
            .($shown->count() === 1 && ! $more ? ' conversation' : ' conversations')
            .' for “'.$query.'”';

        return $this->reply($title, $shown, $like);
    }

    /**
     * • Anna #12 · ✅ 3 Nov: “…the words found…” (linked to the topic)
     *
     * @param  iterable<Conversation>  $conversations
     */
    private function reply(string $title, iterable $conversations, string $like): CommandReply
    {
        $reply = CommandReply::make('🔎 '.$title);
        $tz = WorkingHours::fromConfig()->tz;

        foreach ($conversations as $conversation) {
            $link = Dashboard::topicLink($conversation);
            $label = ($conversation->name ?: 'Guest').' #'.$conversation->id
                .' · '.self::status($conversation->status)
                .' '.self::date($conversation->last_activity_at ?? $conversation->created_at, $tz);

            $value = ($like !== '' ? $this->snippet($conversation, $like) : null)
                ?? ($link !== null ? 'open the topic' : 'topic deleted');

            $reply->line($label, $value, $link);
        }

        return $reply;
    }

    /** The newest message with the words, cut around them. */
    private function snippet(Conversation $conversation, string $like): ?string
    {
        $body = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('direction', [Direction::Visitor, Direction::Agent, Direction::Note])
            ->where('body', 'like', $like)
            ->orderByDesc('id')
            ->value('body');

        if (! is_string($body)) {
            return null;
        }

        $body = (string) preg_replace('/\s+/u', ' ', $body);
        $needle = stripcslashes(trim($like, '%'));
        $at = mb_stripos($body, $needle);
        $start = max(0, ($at === false ? 0 : $at) - intdiv(self::SNIPPET, 2));
        $cut = mb_substr($body, $start, self::SNIPPET);

        return '“'.($start > 0 ? '…' : '').$cut.($start + self::SNIPPET < mb_strlen($body) ? '…' : '').'”';
    }

    private static function status(ConversationStatus $status): string
    {
        return match ($status) {
            ConversationStatus::Waiting => '❗',
            ConversationStatus::Answered => '💬',
            ConversationStatus::Closed => '✅',
        };
    }

    private static function date(?Carbon $at, string $tz): string
    {
        if ($at === null) {
            return '';
        }

        $local = $at->copy()->setTimezone($tz);

        return $local->isToday() ? $local->format('H:i') : $local->format('j M');
    }
}
