<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SuborgUser extends Model
{
    protected $table = 'suborg_users';

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }
}
