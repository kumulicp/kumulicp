<?php

namespace App\Rules;

use App\AppInstance;
use App\Organization;
use Illuminate\Contracts\Validation\Rule;

class OrgAppInstance implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct(private Organization $organization)
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
        $app_instance = AppInstance::find($value);

        return $app_instance?->belongsToOrganization($this->organization);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.org_app_instance');
    }
}
