<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrgBackup extends Model
{
    use HasFactory;

    protected $table = 'org_backups';

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function app_instance()
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }

    public function org_server()
    {
        return $this->belongsTo(OrgServer::class, 'org_server_id');
    }

    public function backup_schedule()
    {
        return $this->belongsTo(BackupSchedule::class, 'scheduled_backup_id');
    }
}
