<?php

namespace App;

use App\Support\Facades\ServerInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
