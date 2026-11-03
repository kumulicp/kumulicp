<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The newest message a person has seen on a ticket.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property int $last_read_message_id
 * @property Carbon|null $read_at
 */
class TicketRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id'];

    protected $table = 'ticket_reads';

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
