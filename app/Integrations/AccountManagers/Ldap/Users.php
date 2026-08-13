<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\AppInstance;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Ldap\Models\OrganizationalUnit;
use App\Ldap\Models\User as LdapUser;
use App\Mail\CustomInvoice;
use App\Mail\SubscriptionBilling;
use App\Services\AppInstanceService;
use App\Support\Facades\Organization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;

class Users
{
    public function __construct(private ?\App\Organization $organization = null)
    {
        if (! $organization) {
            $this->organization = Organization::account();
        }
    }

    public function all()
    {
        $get_users = LdapUser::in(Dn::create($this->organization, 'users'))->get();
        $users = collect([]);

        foreach ($get_users as $user) {
            $users->push(new User($user));
        }

        return $users;
    }

    public function add($input)
    {
        $cn = $input['username'];
        $user_dn = Dn::create($this->organization, 'users', $cn);

        if (! $user = LdapUser::find($user_dn)) {
            $user = new LdapUser;
            $user->setAttribute('cn', $cn);
            $user->setAttribute('displayName', $input['first_name'].' '.$input['last_name']);
            $user->setAttribute('givenname', $input['first_name']);
            $user->setAttribute('sn', $input['last_name']);
            if (Arr::has($input, 'phone_number')) {
                $user->setAttribute('telephoneNumber', $input['phone_number']);
            }
            $user->setAttribute('uid', $cn);
            $user->setAttribute('mail', $input['email']);

            $user->setDn($user_dn);
            $user->setPassword($input['password']);

            $user->save();
        }

        $user = LdapUser::find($user->getDn());

        if (! $user instanceof LdapUser) {
            return null;
        }

        return $this->get($user);
    }

    public function find(string $username)
    {
        if (($user = LdapUser::find(Dn::create($this->organization, 'users', $username))) instanceof LdapUser) {
            return new User($user);
        }
    }

    public function findEmail($user_email)
    {
        $user = LdapUser::where('mail', '=', $user_email)->first();

        if ($user instanceof LdapUser) {
            return $this->get($user);
        }

        return null;
    }

    public function orgAdmins()
    {
        $admins = Group::find(Dn::create('server', 'controlPanelAccess', 'orgAdmin'));
        $n = [];

        if ($admins) {
            $users = OrganizationalUnit::find(Dn::create($this->organization, 'users'));

            foreach ($admins->getAttribute('member') as $admin) {
                $admin = LdapUser::find($admin);
                if ($admin instanceof LdapUser && $admin->isChildOf($users)) {
                    $n[] = $this->get($admin);
                }
            }
        }

        return collect($n);
    }

    public function billingManagers()
    {
        $managers = Group::find(Dn::create($this->organization, 'controlcenter', 'Billing Managers'));

        $billing_managers = collect();

        if ($managers instanceof Group) {
            $billing_managers = $managers->members()->where('objectClass', 'person')->get();
        }

        return $billing_managers->map(function ($manager) {
            return [
                'id' => $manager->getFirstAttribute('cn'),
                'name' => $manager->getFirstAttribute('displayName'),
                'email' => $manager->getFirstAttribute('mail'),
            ];
        });
    }

    public function standardUsers()
    {
        if (! $this->organization->parent_organization_id) {
            $users = LdapUser::in(Dn::create($this->organization, 'users'))->where('employeeType', 'standard')->get();
        } else {
            $users = LdapUser::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList())->where('employeeType', 'standard')->get();
        }

        return $users->map(function ($user, $value) {
            return new User($user);
        });
    }

    public function basicUsers()
    {
        if (! $this->organization->parent_organization_id) {
            $users = LdapUser::in(Dn::create($this->organization, 'users'))->where('employeeType', 'basic')->get();
        } else {
            $users = LdapUser::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList())->where('employeeType', 'basic')->get();
        }

        return $users->map(function ($user, $value) {
            return new User($user);
        });
    }

    public function appUsers(AppInstance $app_instance)
    {
        $application = $app_instance->application;
        $organization = $app_instance->organization;
        $members = collect();

        $roles = (new AppInstanceService($app_instance))->standardRoles();

        foreach ($roles as $role) {
            $group_dn = Dn::create($organization, 'applications', [$role->app_slug($app_instance), $app_instance->name]);
            $group_directory = Group::find($group_dn);

            if ($group_directory instanceof Group) {
                if ($get_members = $group_directory->members()->where('objectClass', 'person')->get()) {
                    foreach ($get_members as $member) {
                        if (! $members->contains(function ($value, $key) use ($member) {
                            return $value == $member;
                        })) {
                            $members->push(new User($member));
                        }
                    }
                }
            }
        }

        return $members;
    }

    public function appStandardUsers(AppInstance $app_instance)
    {
        $application = $app_instance->application;
        $organization = $app_instance->organization;
        $members = [];

        $group_members = collect();
        $roles = (new AppInstanceService($app_instance))->standardRoles();
        if ($parent_app = $app_instance->parent) {
            $app_instance = $parent_app;
        }

        foreach ($roles as $role) {
            $group_dn = Dn::create($organization, 'applications', [$role->app_slug($app_instance), $app_instance->name]);
            $group_directory = Group::find($group_dn);

            if ($group_directory instanceof Group) {
                if ($get_members = $group_directory->members()->where('employeeType', 'standard')->get()) {
                    foreach ($get_members as $member) {
                        if (! $group_members->contains(function ($value, $key) use ($member) {
                            return $value == $member;
                        })) {
                            $group_members->push($member);
                        }
                    }
                }
            }
        }

        return $group_members;
    }

    public function appBasicUsers(AppInstance $app_instance)
    {
        $application = $app_instance->application;
        $organization = $app_instance->organization;
        $members = [];
        $group_members = [];

        $roles = (new AppInstanceService($app_instance))->basicRoles();
        if ($parent_app = $app_instance->parent) {
            $app_instance = $parent_app;
        }

        foreach ($roles as $role) {
            $group_dn = Dn::create($organization, 'applications', [$role->app_slug($app_instance), $app_instance->name]);
            $group_directory = Group::find($group_dn);

            if ($group_directory instanceof Group) {
                $group_members = $group_directory->members()->where('employeeType', 'basic')->get();

                foreach ($group_members as $member) {
                    if (! in_array($member, $members) && in_array('organizationalPerson', $member->getAttribute('objectClass'))) {
                        $members[] = $member;
                    }
                }
            }
        }

        return $members;
    }

    public function notifyBillingManagers($invoice, string $type = 'subscription', ?string $description = '', ?string $price = '')
    {
        $managers = Group::find(Dn::create($this->organization, 'controlcenter', 'Billing Managers'));

        $billing_managers = [];

        if ($managers instanceof Group) {
            $billing_managers = $managers->members()->get();
            foreach ($billing_managers as $manager) {
                if ($manager->getFirstAttribute('mail')) {
                    if ($type === 'subscription') {
                        Mail::to($manager->getFirstAttribute('mail'))->send(new SubscriptionBilling($invoice));
                    } elseif ($type === 'custom') {
                        Mail::to($manager->getFirstAttribute('mail'))->send(new CustomInvoice($invoice, $description, $price));
                    }
                }
            }
        }
    }

    public function updateAllUsersAccessType()
    {
        foreach ($this->all() as $user) {
            $user->permissions()->updateUserAccessType();
        }
    }

    public function get(LdapUser $user)
    {
        return new User($user);
    }

    public function getUserList()
    {
        $user_list = ['blank'];
        foreach ($this->organization->suborg_users as $user) {
            $user_list[] = $user->username;
        }

        return $user_list;
    }

    public function collect()
    {
        if (! $this->organization->parent_organization_id) {
            $users = LdapUser::in(Dn::create($this->organization, 'users'));
        } else {
            $users = LdapUser::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList());
        }

        return $users->orderBy('givenName')->get()->map(function ($user, $value) {
            return new User($user);
        });
    }

    public function paginate(int $items_per_page)
    {
        if (! $this->organization->parent_organization_id) {
            $users = LdapUser::in(Dn::create($this->organization, 'users'));
        } else {
            $users = LdapUser::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList());
        }

        return $users->orderBy('givenName')->paginate($items_per_page);
    }

    public function map($users)
    {
        return $users->map(function ($user, $value) {
            return new User($user);
        });
    }
}
