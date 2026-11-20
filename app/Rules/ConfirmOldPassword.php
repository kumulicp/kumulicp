<?php

namespace App\Rules;

use App\Support\Facades\AccountManager;
use Illuminate\Contracts\Validation\Rule;

class ConfirmOldPassword implements Rule
{
    /**
     * Create a new rule instance. Someone who is signed in is found by their username: the
     * email the panel holds for them is only refreshed at sign in, so it can be one the
     * directory no longer has. Without a username (the public change password page) the
     * email is all there is.
     *
     * @return void
     */
    public function __construct(private string $email, private ?string $username = null) {}

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $user = $this->username !== null
            ? AccountManager::users()->find($this->username)
            : AccountManager::users()->findEmail($this->email);

        // No such user: nothing to confirm against
        return $user !== null && $user->isPassword($value);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.confirm_old_password');
    }
}
