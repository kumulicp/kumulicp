<?php

namespace App\Integrations\AccountManagers\Database;

use App\AppInstance;
use App\Mail\CustomInvoice;
use App\Mail\SubscriptionBilling;
use App\Support\Facades\Organization;
use App\Support\Facades\Settings;
use App\User as UserModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

class Users
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
        $users = [];

        foreach ($this->organization->users as $user) {
            $users[] = new User($user);
        }

        return $users;
    }

    public function add($input)
    {
        if (! $user = UserModel::where('username', $input['username'])->first()) {
            $user = new UserModel;
            $user->organization_id = $this->organization->id;
            $user->username = $input['username'];
            $user->name = $input['first_name'].' '.$input['last_name'];
            $user->first_name = $input['first_name'];
            $user->last_name = $input['last_name'];
            if (Arr::has($input, 'phone_number')) {
                $user->phone_number = $input['phone_number'];
            }
            $user->email = $input['email'];
            $user->password = Hash::make($input['password']);
            if (Settings::get('installed') != 1) {
                $user->email_verified_at = now();
            }

            $user->save();
        }

        $user_interface = $this->get($user);

        return $user_interface;
    }

    public function find(string $username)
    {
        if ($user = UserModel::where('username', $username)->first()) {
            return new User($user);
        }
    }

    public function findEmail(string $user_email)
    {
        $user = UserModel::where('email', $user_email)->first();

        if ($user) {
            return $this->get($user);
        }

        return null;
    }

    public function orgAdmins()
    {
        $admins = [];

        foreach ($this->organization->users()->role('organization_admin')->get() as $admin) {
            $admins[] = new User($admin);
        }

        return $admins;
    }

    public function billingManagers()
    {
        $billing_managers = [];

        foreach ($this->organization->users()->role('billing_manager')->get() as $billing_manager) {
            $billing_managers[] = new User($billing_manager);
        }

        return collect($billing_managers)->map(function ($manager) {
            return [
                'id' => $manager->username,
                'name' => $manager->name,
                'email' => $manager->email,
            ];
        });
    }

    public function standardUsers()
    {
        return $this->organization->users()->where('access_type', 'standard')->get();
    }

    public function basicUsers()
    {
        return $this->organization->users()->where('access_type', 'basic')->get();
    }

    public function appUsers(AppInstance $app_instance)
    {
        return collect();
    }

    public function appStandardUsers(AppInstance $app_instance)
    {
        return collect();
    }

    public function appBasicUsers(AppInstance $app_instance)
    {
        return collect();
    }

    public function notifyBillingManagers($invoice, string $type = 'subscription', ?string $description = '', ?string $price = '')
    {
        $billing_managers = Role::where('name', 'billing_manager')->get();

        foreach ($billing_managers as $manager) {
            if ($manager->email) {
                if ($type === 'subscription') {
                    Mail::to($manager->email)->send(new SubscriptionBilling($invoice));
                } elseif ($type === 'connection') {
                    Mail::to($manager->email)->send(new CustomInvoice($invoice, $description, $price));
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

    public function get(UserModel $user)
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
        $users = collect();

        foreach ($this->organization->users as $user) {
            $users->push(new User($user));
        }

        foreach ($this->organization->suborganizations as $suborg) {
            foreach ($suborg->users as $user) {
                $users->push(new User($user));
            }
        }

        return $users;
    }

    public function paginate(int $items_per_page)
    {
        if (! $this->organization->parent_organization_id) {
            return UserModel::where('organization_id', $this->organization->id)->paginate($items_per_page);
        }

        return UserModel::where('organization_id', $this->organization->id)
            ->whereIn('username', $this->getUserList())
            ->paginate($items_per_page);
    }

    public function map($users)
    {
        return $users->map(function ($user, $value) {
            return new User($user);
        });
    }
}
