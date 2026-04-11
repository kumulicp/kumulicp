<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class NewUserAccountEmailCheck implements Rule
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
        $organization = auth()->user()->organization;

        if ($organization->domain_name && str_contains($value, $organization->domain_name)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.new_user_account_email_check');
    }
}
