<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property string|null $level
 * @property string|null $message
 * @property string|null $created_at
 * @property-read \App\Organization|null $organization
 */
class Log extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d',
        ];
    }

    public function organization()
    {
        return $this->belongsTo('App\Organization');
    }
}
