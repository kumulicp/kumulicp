<?php

namespace App;

use App\Support\Facades\ServerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $server_id
 * @property int|null $backup_server_id
 * @property string|null $name
 * @property string|null $status
 * @property-read Organization|null $organization
 * @property-read Collection<int, AppInstance> $application_webs
 * @property-read Collection<int, AppInstance> $application_databases
 * @property-read Collection<int, OrgDomain> $domain_email
 * @property-read Server|null $server
 * @property-read OrgServer|null $backup_server
 */
class OrgServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'server_id',
        'backup_server_id',
        'server_customer_id',
        'backup_driver',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
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

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
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
