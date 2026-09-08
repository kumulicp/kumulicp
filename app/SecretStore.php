<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configured secret-store backend that one or more Servers can be pointed
 * at for the operational secrets they need (LDAP bind password, initial
 * admin password, database password, etc.) - not for .env config or user
 * login passwords.
 *
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property bool $is_default
 * @property string|null $address
 * @property string|null $role_id
 * @property string|null $secret_id
 * @property string|null $mount_path
 * @property string|null $namespace
 * @property array|null $config
 * @property string $status
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Server> $servers
 */
class SecretStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'driver',
        'is_default',
        'address',
        'role_id',
        'secret_id',
        'mount_path',
        'namespace',
        'config',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'role_id' => 'encrypted',
        'secret_id' => 'encrypted',
        'config' => 'encrypted:array',
    ];

    protected $hidden = [
        'role_id',
        'secret_id',
    ];

    public function servers()
    {
        return $this->hasMany('App\Server');
    }

    public function inUse(): bool
    {
        return $this->servers()->exists();
    }

    public static function default(): self
    {
        return static::where('is_default', true)->firstOrFail();
    }
}
