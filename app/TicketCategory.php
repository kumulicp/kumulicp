<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $default_support_assignee_id
 * @property int|null $sla_policy_id
 * @property bool $active
 * @property int $sort
 */
class TicketCategory extends Model
{
    protected $table = 'ticket_categories';

    protected $fillable = ['name', 'slug', 'active', 'sort', 'default_support_assignee_id', 'sla_policy_id'];

    protected $casts = [
        'active' => 'boolean',
    ];
}
