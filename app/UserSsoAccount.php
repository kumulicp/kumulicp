<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $user_id
 * @property int $sso_provider_id
 * @property string|null $provider_user_id
 * @property string|null $email
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $token_expires_at
 * @property-read \App\User $user
 * @property-read \App\SsoProvider $provider
 */
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
