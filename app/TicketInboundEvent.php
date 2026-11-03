<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * An event an outside system has already told us about.
 *
 * @property int $id
 * @property int $integration_id
 * @property string $event_key
 */
class TicketInboundEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_inbound_events';
}
