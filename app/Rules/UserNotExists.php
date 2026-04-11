<?php

namespace App\Rules;

use App\Support\Facades\AccountManager;
use Illuminate\Contracts\Validation\Rule;

class UserNotExists implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
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
        // if (! preg_match('/[^A-Za-z0-9]/', $value)) { // Needed for LDAP server?
        $user = AccountManager::checkUsername($value);

        return is_null($user);
        /*}

        return false;*/
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.user_exists');
    }
}
