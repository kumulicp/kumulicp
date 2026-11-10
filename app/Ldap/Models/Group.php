<?php

namespace App\Ldap\Models;

use App\AppInstance;
use App\AppRole;
use App\Enums\AccessType;
use App\Ldap\Actions\Dn;
use App\Organization;
use Illuminate\Support\Arr;
use LdapRecord\Models\Attributes\DistinguishedName;
use LdapRecord\Models\Model;

/**
 * @property array|string|null $cn
 * @property array|string|null $description
 * @property array|string|null $member
 */
class Group extends Model
{
    private $organization;

    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [
        'top',
        'groupOfNames',
    ];

    public function members()
    {
        return $this->hasMany([
            Group::class, User::class, EmailUser::class,
        ], 'memberof')->using($this, 'member');
    }

    public function managers()
    {
        return $this->hasManyIn(User::class, 'owner');
    }

    public function account()
    {
        if ($this->organization) {
            return $this->organization;
        }

        $dn = DistinguishedName::make($this->getDn());
        $organization = $dn->assoc()['o'][0];

        $this->organization = Organization::where('slug', $organization)->first();

        return $this->organization;
    }

    public function roleName()
    {
        $dn = Dn::split($this->getDn());
        if ($dn['ou'][0] !== 'applications' || count(Arr::get($dn, 'cn', [])) <= 1) {
            return null;
        }

        return str_replace($dn['cn'][0].'-', '', $dn['cn'][1]);
    }

    public function groupInfo()
    {
        $dn = Dn::split($this->getDn());
        if ($dn['ou'][0] !== 'applications') {
            return null;
        }
        $count = count($dn['cn']);
        $app = AppInstance::where('name', $dn['cn'][0])->first();

        if ($app && $app->belongsToOrganization($this->account())) {
            if ($count > 1 && $app_role = $this->roleOf($dn['cn'][1])) {
                return [
                    'access_type' => $app_role->accessType(app: $app),
                ];
            }
        } else {
            $this->delete();
        }

        return null;
    }

    // Apps can have roles with the same slug (an Administrator in each), so it is the one of the app
    // the group is for when there is more than one
    public function appRole()
    {
        return $this->roleOf($this->roleName());
    }

    private function roleOf(?string $slug): ?AppRole
    {
        $roles = AppRole::where('slug', $slug)->get();

        if ($roles->count() <= 1) {
            return $roles->first();
        }

        $dn = Dn::split($this->getDn());
        $app = isset($dn['cn'][0]) ? AppInstance::where('name', $dn['cn'][0])->first() : null;
        $application_ids = $app ? $app->children->pluck('application_id')->push($app->application_id) : collect();

        return $roles->first(fn (AppRole $role) => $application_ids->contains($role->application_id)) ?? $roles->first();
    }

    public function appRoleAccessType(): ?AccessType
    {
        $role_name = $this->roleName();
        if (in_array($role_name, ['standard', 'basic'])) {
            return AccessType::from($role_name);
        } else {
            return $this->appRole()?->access_type;
        }
    }

    public function application()
    {
        $dn = Dn::split($this->getDn());
        $app_name = $dn['cn'][0];
        $app_instance = AppInstance::where('name', $app_name)->first();
        $app_role = $this->appRole();

        if (! $app_role || $app_role->application_id === $app_instance?->application_id) {
            return $app_instance;
        } else {
            foreach ($app_instance->children as $child) {
                if ($app_role->application_id === $child->application_id) {
                    return $child;
                }
            }
        }
    }

    public function appName()
    {
        $dn = Dn::split($this->getDn());

        if (Arr::has($dn, 'cn') && is_array($dn['cn'])) {
            if ($app = AppInstance::where('name', $dn['cn'][0])->first()) {
                return $app->label;
            }
        }
    }

    public function permission()
    {
        $dn = Dn::split($this->getDn());

        if (count($dn['cn']) > 1) {
            return $this->roleOf($dn['cn'][1]);
        }

        return null;
    }

    public function app(Organization $organization)
    {
        $dn = Dn::split($this->getDn());
        if (array_key_exists('cn', $dn)) {
            $app_name = $dn['cn'][0];
            $app_instance = $organization->app_instances()->where('name', $app_name)->first();

            if ($app_instance) {
                if (count($dn['cn']) > 1) {
                    $role_name = $this->appRole();
                    if ($app_instance->version->hasRole($role_name)) {
                        return $app_instance;
                    } else {
                        foreach ($app_instance->children as $child_app) {
                            if ($child_app->version->hasRole($role_name)) {
                                return $child_app;
                            }
                        }
                    }
                }
            }

            return $app_instance;
        }

        return null;
    }
}
