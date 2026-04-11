<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SsoProvider extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'driver',
        'client_id',
        'client_secret',
        'redirect_url',
        'base_url',
        'scopes',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'client_secret' => 'encrypted',
        'token_expires_at' => 'datetime',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    public function accounts()
    {
        return $this->hasMany(UserSsoAccount::class);
    }
}
