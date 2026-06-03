<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $username
 * @property-read \App\Organization $organization
 */
class SuborgUser extends Model
{
    protected $table = 'suborg_users';

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }
}
