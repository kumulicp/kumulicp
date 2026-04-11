<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class MainContact implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($user, $organization)
    {
        $this->user = $user;
        $this->organization = $organization;
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
        return (
            ($this->organization->main_contact_map == $this->user && $value != null)
            || ($this->organization->main_contact_map != $this->user)
        )
        ? true
        : false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.main_contact');
    }
}
