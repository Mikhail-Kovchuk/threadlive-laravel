<?php

declare(strict_types=1);

namespace Threadlive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Threadlive\Enums\ConversationStatus;
use Threadlive\Enums\Direction;
use Threadlive\Enums\TopicState;

/**
 * One visitor's conversation. Only `public_id` ever leaves the server.
 *
 * @property int $id
 * @property string $public_id
 * @property ConversationStatus $status
 * @property string|null $user_ref
 * @property string|null $visitor_token_hash
 * @property string|null $name
 * @property string|null $email
 * @property string|null $locale
 * @property array<string, mixed>|null $context
 * @property int|null $telegram_thread_id
 * @property TopicState $topic_state
 * @property string|null $topic_name
 * @property Carbon|null $topic_closed_at
 * @property Carbon|null $topic_deleted_at
 * @property int|null $assignee_tg_id
 * @property string|null $assignee_name
 * @property Carbon|null $assigned_at
 * @property Carbon|null $last_visitor_message_at
 * @property Carbon|null $last_agent_message_at
 * @property Carbon|null $last_activity_at
 * @property int|null $visitor_read_message_id
 * @property Carbon|null $waiting_alert_sent_at
 * @property Carbon|null $unread_email_sent_at
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Conversation extends Model
{
    protected $table = 'threadlive_conversations';

    protected $guarded = [];

    protected $attributes = [
        'status' => 'waiting',
        'topic_state' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'topic_state' => TopicState::class,
            'context' => 'array',
            'telegram_thread_id' => 'integer',
            'assignee_tg_id' => 'integer',
            'visitor_read_message_id' => 'integer',
            'topic_closed_at' => 'datetime',
            'topic_deleted_at' => 'datetime',
            'assigned_at' => 'datetime',
            'last_visitor_message_at' => 'datetime',
            'last_agent_message_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'waiting_alert_sent_at' => 'datetime',
            'unread_email_sent_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Agent replies after the last one the visitor has seen. */
    public function unreadCount(): int
    {
        return $this->messages()
            ->where('direction', Direction::Agent)
            ->where('id', '>', $this->visitor_read_message_id ?? 0)
            ->count();
    }
}
