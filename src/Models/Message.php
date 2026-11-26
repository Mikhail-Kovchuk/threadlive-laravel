<?php

declare(strict_types=1);

namespace Threadlive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Threadlive\Enums\Delivery;
use Threadlive\Enums\Direction;

/**
 * @property int $id
 * @property int $conversation_id
 * @property Direction $direction
 * @property string $body
 * @property array{text: string, from: string, to: string}|null $translation
 * @property array<int, array<string, mixed>>|null $attachments
 * @property string|null $client_msg_id
 * @property int|null $telegram_message_id
 * @property int|null $telegram_from_id
 * @property string|null $agent_name
 * @property bool $by_assistant an agent message the AI assistant wrote
 * @property Delivery|null $delivery
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 */
class Message extends Model
{
    protected $table = 'threadlive_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'delivery' => Delivery::class,
            'attachments' => 'array',
            'translation' => 'array',
            'telegram_message_id' => 'integer',
            'telegram_from_id' => 'integer',
            'edited_at' => 'datetime',
            'by_assistant' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
