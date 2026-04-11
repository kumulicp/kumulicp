<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class OrgDomainName implements Rule
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
        $domain = $organization->domains()->where('id', $value)->first();

        return ! is_null($domain);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.org_domain_name');
    }
}
