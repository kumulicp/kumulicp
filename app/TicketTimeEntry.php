<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time a staff member spent on a ticket.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property int $minutes
 * @property string|null $note
 * @property bool $billable
 * @property Carbon $worked_at
 * @property-read User|null $user
 */
class TicketTimeEntry extends Model
{
    protected $table = 'ticket_time_entries';

    protected $casts = [
        'billable' => 'boolean',
        'worked_at' => 'date',
        'minutes' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
