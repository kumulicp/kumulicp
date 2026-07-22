<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $recurring_backup_id
 * @property string|null $scheduled_at
 * @property string|null $status
 * @property-read \App\RecurringBackup|null $recurring_backup
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\OrgBackup> $backups
 */
class BackupSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurring_backup_id',
        'scheduled_at',
    ];

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
