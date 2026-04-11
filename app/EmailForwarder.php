<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EmailForwarder extends Model
{
    protected $table = 'email_forwarders';

    public function domain()
    {
        return $this->belongsTo('App\OrgDomain', 'domain_id');
    }
}
