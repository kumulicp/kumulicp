<?php

namespace App\Services;

use App\Integrations\AccountManagers\Ldap\AccountManagerInterface;
use App\Organization;

class AccountManagerService
{
    private $interfaces = [
        'ldap' => AccountManagerInterface::class,
        'db' => \App\Integrations\AccountManagers\Database\AccountManagerInterface::class,
    ];

    private $driver;

    public function __construct()
    {
        $this->driver = env('ACCOUNTMANAGER_DRIVER', 'db');
    }

    public function interface()
    {
        if (array_key_exists($this->driver, $this->interfaces)) {
            $interface = $this->interfaces[$this->driver];

            return new $interface;
        }

        throw new \Exception(__('messages.exception.account_manager_driver_fail'));
    }

    public function driver()
    {
        return $this->driver;
    }

    public function accounts()
    {
        return $this->interface()->accounts();

    }

    public function account(?Organization $organization = null)
    {
        return $this->accounts()->account($organization);
    }

    public function users(?Organization $organization = null)
    {
        return $this->accounts()->users($organization);
    }

    public function groups()
    {
        return $this->accounts()->groups();
    }

    public function testConnection()
    {
        return $this->interface()->testConnection();
    }

    public function initiate()
    {
        return $this->interface()->initiate();
    }

    public function checkUsername(string $username)
    {
        return $this->interface()->checkUsername($username);
    }

    public function checkEmail(string $email)
    {
        return $this->interface()->checkEmail($email);
    }
}
