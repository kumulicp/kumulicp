<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's relationship to one ticket beyond the roles on the ticket
 * itself: watching it (CC) and/or muting its notifications.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $side customer|support
 * @property bool $watching
 * @property bool $muted
 */
class TicketSubscription extends Model
{
    public const CUSTOMER = 'customer';

    public const SUPPORT = 'support';

    protected $table = 'ticket_subscriptions';

    // side, watching and muted are always set deliberately
    protected $fillable = ['ticket_id', 'user_id'];

    protected $casts = [
        'watching' => 'boolean',
        'muted' => 'boolean',
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
