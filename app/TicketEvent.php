<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing that happened to a ticket. Append-only; deleted with its ticket.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $actor_id
 * @property string $type
 * @property array|null $context
 * @property bool $internal
 * @property Carbon|null $created_at
 */
class TicketEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_events';

    protected $casts = [
        'context' => 'array',
        'internal' => 'boolean',
    ];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
