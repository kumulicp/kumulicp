<?php

namespace App\Rules;

use App\Support\Facades\AccountManager;
use Illuminate\Contracts\Validation\Rule;

class EmailAddressExists implements Rule
{
    private $user;

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct(?string $username = null)
    {
        if ($username && $user = AccountManager::users()->find($username)) {
            $this->user = $user->attribute('username');
        }
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        // Checks if email address is being used by anyone else in the LDAP directory
        $user = AccountManager::checkEmail($value);

        return (($user && ! $this->user) || ($user && $this->user && $user != $this->user)) ? false : true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.email_address_exists');
    }
}
