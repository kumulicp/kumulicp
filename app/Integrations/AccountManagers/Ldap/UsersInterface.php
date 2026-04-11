<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\AppInstance;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Ldap\Models\OrganizationalUnit;
use App\Ldap\Models\User;
use App\Mail\CustomInvoice;
use App\Mail\SubscriptionBilling;
use App\Services\AppInstanceService;
use App\Support\Facades\Organization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;

class UsersInterface
{
    private $users = [];

    public function __construct(private ?\App\Organization $organization = null)
    {
        if (! $organization) {
            $this->organization = Organization::account();
        }
    }

    public function all()
    {
        $get_users = User::in(Dn::create($this->organization, 'users'))->get();
        $users = collect([]);

        foreach ($get_users as $user) {
            $users->push(new UserInterface($user));
        }

        return $users;
    }

    public function add($input)
    {
        $cn = $input['username'];
        $user_dn = Dn::create($this->organization, 'users', $cn);

        if (! $user = User::find($user_dn)) {
            $user = new User;
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

        $user = User::find($user->getDn());

        $user_interface = $this->get($user);

        return $user_interface;
    }

    public function find(string $username)
    {
        if ($user = User::find(Dn::create($this->organization, 'users', $username))) {
            return new UserInterface($user);
        }
    }

    public function findEmail($user_email)
    {
        $user = User::where('mail', '=', $user_email)->first();

        if ($user) {
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
                $admin = User::find($admin);
                if ($admin && $admin->isChildOf($users)) {
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

        if ($managers) {
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
            $users = User::in(Dn::create($this->organization, 'users'))->where('employeeType', 'standard')->get();
        } else {
            $users = User::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList())->where('employeeType', 'standard')->get();
        }

        return $users->map(function ($user, $value) {
            return new UserInterface($user);
        });
    }

    public function basicUsers()
    {
        if (! $this->organization->parent_organization_id) {
            $users = User::in(Dn::create($this->organization, 'users'))->where('employeeType', 'basic')->get();
        } else {
            $users = User::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList())->where('employeeType', 'basic')->get();
        }

        return $users->map(function ($user, $value) {
            return new UserInterface($user);
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

            if ($group_directory) {
                if ($get_members = $group_directory->members()->where('objectClass', 'person')->get()) {
                    foreach ($get_members as $member) {
                        if (! $members->contains(function ($value, $key) use ($member) {
                            return $value == $member;
                        })) {
                            $members->push(new UserInterface($member));
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

            if ($group_directory) {
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

        return $group_members ?? collect();
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

            if ($group_directory) {
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

        if ($managers) {
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

    public function get(User $user)
    {
        return new UserInterface($user);
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
            $users = User::in(Dn::create($this->organization, 'users'));
        } else {
            $users = User::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList());
        }

        return $users->orderBy('givenName')->get()->map(function ($user, $value) {
            return new UserInterface($user);
        });
    }

    public function paginate(int $items_per_page)
    {
        if (! $this->organization->parent_organization_id) {
            $users = User::in(Dn::create($this->organization, 'users'));
        } else {
            $users = User::in(Dn::create($this->organization, 'users'))->whereIn('cn', $this->getUserList());
        }

        return $users->orderBy()->paginate($items_per_page);
    }

    public function map($users)
    {
        return $users->map(function ($user, $value) {
            return new UserInterface($user);
        });
    }
}
