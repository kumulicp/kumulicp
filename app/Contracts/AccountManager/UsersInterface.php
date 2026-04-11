<?php

namespace App\Contracts\AccountManager;

use App\AppInstance;

interface UsersInterface
{
    public function add($input);

    public function find($user);

    public function findEmail($user_email);

    public function orgAdmins();

    public function billingManagers();

    public function standardUsers();

    public function basicUsers();

    public function appUsers(AppInstance $app_instance);

    public function appStandardUsers(AppInstance $app_instance);

    public function appBasicUsers(AppInstance $app_instance);

    public function notifyBillingManagers($invoice);

    public function get(User $user);

    public function collect();
}
