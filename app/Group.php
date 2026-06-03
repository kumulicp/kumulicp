<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $type
 * @property string|null $description
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\User> $members
 */
class Group extends Model
{
    public function members()
    {
        return $this->belongsToMany(User::class, 'group_members')->withPivot('role');
    }
}
