<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NewUserCode extends Model
{
    protected $table = 'new_user_code';

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function generate($user)
    {
        $code = Str::password(20, true, true, false);

        $this->username = $user;
        $this->code = $code;

        return $this;
    }
}
