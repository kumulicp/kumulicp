<?php

namespace App\Rules;

use App\Support\Facades\Organization;
use Illuminate\Contracts\Validation\Rule;

class AccountEmailChecks implements Rule
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
        $organization = Organization::account();

        // Checks if email has organization's domain address but only if email is setup using our services. If organization isn't using our services, this means they using another service for their organization's email accounts and therefore is allowed to use this email as their primary email
        if ($organization->domain_name && (($organization->domain_type != 2 || $organization->custom_email != 1) && str_contains($value, $organization->domain_name))) {
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
        return __('messages.rule.account_email_check');
    }
}
