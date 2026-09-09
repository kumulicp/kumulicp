<?php

namespace App;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $type
 * @property string|null $description
 * @property-read Collection<int, User> $members
 */
class Group extends Model
{
    public function members()
    {
        return $this->belongsToMany(User::class, 'group_members')->using(GroupMember::class)->withPivot('role');
    }
}
