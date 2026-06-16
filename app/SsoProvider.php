<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $display_name
 * @property string $driver
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $redirect_url
 * @property string|null $base_url
 * @property string|null $scopes
 * @property bool $enabled
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property \Carbon\Carbon|null $token_expires_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\UserSsoAccount> $accounts
 */
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

    protected $hidden = [
        'client_secret',
        'access_token',
        'refresh_token',
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
