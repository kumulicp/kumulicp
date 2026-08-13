<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $group_id
 * @property string $user_id
 * @property string|null $role
 * @property-read \App\Group $group
 * @property-read \App\User $user
 */
class GroupMember extends Pivot
{
    public $incrementing = true;

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
