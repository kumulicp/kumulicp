<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserRecoveryEmail extends Model
{
    protected $table = 'user_recovery_emails';

    protected $fillable = ['username', 'email'];
}
