<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The previous body of an edited message. Append-only.
 *
 * @property int $id
 * @property int $ticket_message_id
 * @property string $body_html
 * @property int|null $edited_by
 * @property Carbon|null $created_at
 */
class TicketMessageRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_message_revisions';
}
