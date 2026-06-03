<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $domain_id
 * @property string $email
 * @property string $destination
 * @property-read \App\OrgDomain $domain
 */
class EmailForwarder extends Model
{
    protected $table = 'email_forwarders';

    public function domain()
    {
        return $this->belongsTo('App\OrgDomain', 'domain_id');
    }
}
