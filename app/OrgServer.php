<?php

namespace App;

use App\Support\Facades\ServerInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $server_id
 * @property int|null $backup_server_id
 * @property string|null $name
 * @property string|null $status
 * @property-read \App\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppInstance> $application_webs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppInstance> $application_databases
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\OrgDomain> $domain_email
 * @property-read \App\Server|null $server
 * @property-read \App\OrgServer|null $backup_server
 */
class OrgServer extends Model
{
    use HasFactory;

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function application_webs()
    {
        return $this->hasMany('App\AppInstance', 'web_server_id');
    }

    public function application_databases()
    {
        return $this->hasMany('App\AppInstance', 'database_server_id');
    }

    public function domain_email()
    {
        return $this->hasMany('App\OrgDomain', 'email_server_id');
    }

    public function server()
    {
        return $this->belongsTo('App\Server', 'server_id');
    }

    public function connect()
    {
        return ServerInterface::connect($this);
    }

    public function backup_server()
    {
        return $this->belongsTo('App\OrgServer', 'backup_server_id');
    }
}
