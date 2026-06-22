<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $assignable_type
 * @property int $assignable_id
 * @property string $server_type
 * @property int $server_id
 * @property-read \App\Server|null $server
 * @property-read \Illuminate\Database\Eloquent\Model|null $assignable
 */
class ServerAssignment extends Model
{
    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function assignable()
    {
        return $this->morphTo();
    }
}
