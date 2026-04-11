<?php

namespace App\Rules;

use App\OrgDomain;
use App\OrgSubdomain;
use Illuminate\Contracts\Validation\Rule;

class SubdomainNotExists implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct(private OrgDomain $domain, private ?OrgSubdomain $subdomain = null)
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

        $find_subdomain = $organization->subdomains()->where('parent_domain_id', $this->domain->id)->where('host', $value)->where('type', 'app')->first();

        return ($this->subdomain && $find_subdomain) ? $this->subdomain->is($find_subdomain) : is_null($find_subdomain);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.subdomain_exists');
    }
}
