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
        $suborganization = $organization->suborganizations()->where('id', $value)->first();

        return $value === $organization->id || ! is_null($suborganization);
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
