<?php

namespace App\Rules;

use App\AppInstance;
use App\OrgSubdomain;
use App\Support\Facades\Application;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\Rule;

class OrgSubdomainAvailable implements DataAwareRule, Rule
{
    protected $data = [];

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct(private AppInstance $app_instance)
    {
        //
    }

    /**
     * Set the data under validation.
     *
     * @param  array  $data
     * @return $this
     */
    public function setData($data)
    {
        $this->data = $data;

        return $this;
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
        $subdomain = OrgSubdomain::where('host', $value)->where('parent_domain_id', $this->data['parent_domain'])->first();

        return ! $subdomain || Application::instance($this->app_instance)->isSubdomainAvailable($subdomain);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.org_subdomain_message');
    }
}
