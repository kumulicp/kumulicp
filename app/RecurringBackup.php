<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $server_id
 * @property int|null $application_id
 * @property string|null $recurrence
 * @property string|null $time
 * @property string|null $last_scheduled_at
 * @property-read \App\Server|null $server
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\BackupSchedule> $scheduled
 * @property-read \App\Organization $organization
 * @property-read \App\Application|null $application
 */
class RecurringBackup extends Model
{
    use HasFactory;

    protected $table = 'recurring_backups';

    protected $fillable = [
        'server_id',
        'organization_id',
        'application_id',
        'recurrence',
        'type',
        'time',
        'delete_after',
        'delete_interval',
        'status',
        'last_scheduled_at',
    ];

    public function server()
    {
        return $this->belongsTo('App\Server', 'server_id');
    }

    public function scheduled()
    {
        return $this->hasMany('App\BackupSchedule', 'recurring_backup_id');
    }

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function application()
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    public function nextDateTime()
    {
        if (isset($this->last_scheduled_at)) {
            $dt = new Carbon($this->last_scheduled_at);

            switch ($this->recurrence) {
                case 'daily':
                    return $dt->addHours(24);
                case 'monthly':
                    return $dt->addMonth();
            }
        } else {
            $now = Carbon::now();
            $dt = Carbon::createFromTimeString($this->time);

            if ($dt < $now) {

                return $dt->addHours(24);
            }

            return $dt;
        }
    }
}
