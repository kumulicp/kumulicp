<?php

namespace App\Ldap\Models;

use App\AppInstance;
use App\AppRole;
use App\Ldap\Actions\Dn;
use App\SuborgUser;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use LdapRecord\Models\Attributes\Password;
use LdapRecord\Models\Concerns\CanAuthenticate;
use LdapRecord\Models\Model;
use LdapRecord\Models\OpenLDAP\Entry;

class User extends Entry implements Authenticatable
{
    use CanAuthenticate, Notifiable;

    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [
        'top',
        'person',
        'organizationalperson',
        'inetorgperson',
    ];

    public function __construct()
    {
        $provider = config('auth.guards.web.provider');
        $object_classes = config("auth.providers.$provider.user_object_classes");
        if ($object_classes && $object_classes !== '') {
            self::$objectClasses = explode(',', $object_classes);
        }
    }

    private $apps = [];

    protected string $guidKey = 'entryUUID';

    public function authIdentifier()
    {
        return $this->getObjectGuid();
    }

    public function organization()
    {
        $user = $this;
        $cache_key = $this->getFirstAttribute('cn').'_organization';

        return FastCache::retrieve($cache_key, function () use ($user) {
            if ($suborg_user = SuborgUser::where('username', $user->getFirstAttribute('cn'))->first()) {
                return $suborg_user->organization;
            } elseif ($user->orgName() === Organization::account()?->slug) {
                return Organization::account();
            }

            return \App\Organization::where('slug', $user->orgName())->first();
        });
    }

    public function orgName()
    {
        $dn = Dn::split($this->getDn());
        $organization = $dn['o'][0];

        return $organization;
    }

    /**
     * Route notifications for the mail channel.
     *
     * @param  Notification  $notification
     * @return array|string
     */
    public function routeNotificationForMail($notification)
    {
        // Return name and email address...
        return [$this->getFirstAttribute('mail') => $this->getFirstAttribute('displayName')];
    }

    public function groups()
    {
        return $this->hasMany(Group::class, 'member');
    }

    public function setPassword(string $password)
    {
        $this->setAttribute('userPassword', Password::md5Crypt($password));
    }

    public function apps()
    {
        if (count($this->apps) > 0) {
            return $this->apps;
        }

        $roles = [];
        $user_apps = [];
        $apps = [];

        foreach ($this->groups()->in(Dn::create($this->orgName(), 'applications'))->get() as $group) {
            $dn = Dn::split($group->getDN());

            $app_name = Arr::get($dn, 'cn.0', null);
            $role_name = $group->roleName();

            if ($app_name && $role_name) {
                $roles[] = [
                    'app' => $app_name,
                    'name' => $role_name,
                ];
            }
        }

        if (count($roles) > 0) {
            foreach ($roles as $role) {
                if ($group = AppRole::where('slug', $role['name'])->first()) {
                    $has_parent = $group->application->parent_app_id;
                    if ($has_parent) {
                        $app = AppInstance::where('name', $role['app'])->first()?->children()->whereNotIn('name', $apps)->first();
                    } else {
                        $app = AppInstance::where('name', $role['app'])->whereNotIn('name', $apps)->first();
                    }

                    if ($app) {
                        $apps[] = $app->name;
                        $user_apps[] = $app;
                    }
                }
            }

            $this->apps = $user_apps;
        }

        return $this->apps;
    }

    public function hasAccessToApps(array $apps, $condition = 'AND')
    {
        $user_apps = [];

        $n = 0;
        foreach ($this->apps() as $app) {
            if (in_array($app->name, $apps)) {
                if ($condition == 'AND') {
                    $n++;
                } elseif ($condition == 'OR') {
                    return true;
                }
            }
        }

        if ($n == count($apps)) {
            return true;
        }

        return false;
    }

    public function hasControlPanelAccess()
    {
        $provider = config('auth.guards.web.provider');
        $group_dns = config("auth.providers.$provider.groups") ?? [];
        $groups = explode('|', $group_dns);

        if ($group_dns && count($groups) > 0) {
            foreach ($groups as $group) {
                if ($this->groups()->exists($group)) {
                    return true;
                }
            }
        }

        return false;
    }
}
