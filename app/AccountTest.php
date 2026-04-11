<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AccountTest extends Model
{
    use HasFactory;

    protected $table = 'account_tests';

    protected $casts = [
        'settings' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo('App\Organization');
    }

    public function organizations()
    {
        return $this->hasMany('App\Organization', 'account_test_id');
    }

    public function created_by()
    {
        return $this->belongsTo('App\User');
    }

    public function setting($setting)
    {
        return Arr::get($this->settings, $setting, null);
    }
}
