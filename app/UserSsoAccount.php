<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class UserSsoAccount extends Model
{
    protected $fillable = [
        'user_id',
        'sso_provider_id',
        'provider_user_id',
        'email',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    protected $dates = [
        'token_expires_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(SsoProvider::class, 'sso_provider_id');
    }
}
