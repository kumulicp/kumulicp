<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $app_instance_id
 * @property int|null $org_server_id
 * @property int|null $scheduled_backup_id
 * @property string|null $status
 * @property string|null $completed_at
 * @property-read \App\Organization $organization
 * @property-read \App\AppInstance|null $app_instance
 * @property-read \App\OrgServer|null $org_server
 * @property-read \App\BackupSchedule|null $backup_schedule
 */
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
