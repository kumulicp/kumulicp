<?php

namespace App\Rules;

use App\Support\Facades\AccountManager;
use Illuminate\Contracts\Validation\Rule;

class GroupNameNotUsed implements Rule
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

        return is_null(AccountManager::groups()->find($value));

    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.group_name_not_used');
    }
}
