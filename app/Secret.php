<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Backing store for the `database` secret store driver - the same kind of
 * operational secret an `openbao`-driver SecretStore would hold externally,
 * kept in-database (encrypted) instead.
 *
 * @property int $id
 * @property int $secret_store_id
 * @property string $path
 * @property string $key
 * @property string|null $value
 * @property-read \App\SecretStore $secretStore
 */
class Secret extends Model
{
    use HasFactory;

    protected $fillable = [
        'secret_store_id',
        'path',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'encrypted',
    ];

    protected $hidden = [
        'value',
    ];

    public function secretStore()
    {
        return $this->belongsTo('App\SecretStore');
    }
}
