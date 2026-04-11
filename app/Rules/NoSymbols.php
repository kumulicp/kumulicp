<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class NoSymbols implements Rule
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
        return ! preg_match('/[^A-Za-z0-9 ]/', $value); // Personal note, needs the ! in front
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.no_symbols');
    }
}
