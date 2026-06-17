<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $username
 * @property string $code
 * @property-read \App\Organization $organization
 */
class NewUserCode extends Model
{
    protected $table = 'new_user_code';

    protected $attributes = [
        'activated' => false,
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function generate($user)
    {
        $code = Str::password(20, true, true, false);

        $this->username = $user;
        $this->code = $code;

        return $this;
    }
}
