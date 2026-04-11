<?php

namespace App\Rules;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\Email;
use Illuminate\Contracts\Validation\Rule;

class LdapEmailNotExists implements Rule
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
        // Checks if email exists in organization's "cn=email" dn
        $organization = auth()->user()->organization;
        $email = Email::find(Dn::create($organization, 'emails', $value));

        return $email ? false : true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.ldap_email_not_exists');
    }
}
