<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupSchedule extends Model
{
    use HasFactory;

    protected $cast = [
        'scheduled_at' => 'datetime',
    ];

    public function recurring_backup()
    {
        return $this->belongsTo('App\RecurringBackup', 'recurring_backup_id');
    }

    public function backups()
    {
        return $this->hasMany('App\OrgBackup', 'scheduled_backup_id');
    }
}
