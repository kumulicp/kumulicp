<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property string|null $pending_email
 * @property \Illuminate\Support\Carbon|null $pending_requested_at
 * @property string|null $previous_email
 * @property \Illuminate\Support\Carbon|null $active_from
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserRecoveryEmail extends Model
{
    protected $table = 'user_recovery_emails';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'pending_requested_at' => 'datetime',
            'active_from' => 'datetime',
        ];
    }
}
