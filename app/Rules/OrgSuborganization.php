<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class OrgSuborganization implements Rule
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

        // The value arrives as a string from a form, and a deactivated suborganization can't be picked
        $suborganization = $organization->suborganizations()
            ->where('id', $value)
            ->where('status', '!=', 'deactivated')
            ->first();

        return (int) $value === $organization->id || ! is_null($suborganization);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.org_suborganization');
    }
}
