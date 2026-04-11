<?php

namespace App;

use App\Enums\AccessType;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use LdapRecord\Laravel\Auth\AuthenticatesWithLdap;
use LdapRecord\Laravel\Auth\LdapAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements LdapAuthenticatable, MustVerifyEmail
{
    use AuthenticatesWithLdap, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'guid', 'organization_id',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'access_type' => AccessType::class,
        'email_verified_at' => 'datetime',
    ];

    /**
     * The relationships that should always be loaded.
     *
     * @var array
     */
    protected $with = ['organization'];

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_members');
    }

    public function ssoAccounts()
    {
        return $this->hasMany(UserSsoAccount::class);
    }

    public function setPassword(string $password)
    {
        $this->password = Hash::make($password);
    }

    public function emailForVerification()
    {
        return $this->email;
    }
}
