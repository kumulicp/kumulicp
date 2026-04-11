<?php

namespace App\Rules;

use App\Support\Facades\AccountManager;
use Illuminate\Contracts\Validation\Rule;

class ConfirmOldPassword implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct(private string $email) {}

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $user = AccountManager::users()->findEmail($this->email);

        return $user->isPassword($value);
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
