<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class RecurringBackup extends Model
{
    use HasFactory;

    protected $table = 'recurring_backups';

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
