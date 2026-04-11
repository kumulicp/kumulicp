<?php

namespace App\Contracts\AccountManager;

interface AccountManagerContract
{
    public function accounts();

    public function initiate();

    public function checkUsername(string $username);

    public function checkEmail(string $email);

    public function testConnection();
}
