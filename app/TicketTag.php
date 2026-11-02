<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class TicketTag extends Model
{
    protected $table = 'ticket_tags';

    protected $fillable = ['name'];
}
