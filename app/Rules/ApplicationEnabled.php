<?php

namespace App\Rules;

use App\Application;
use Illuminate\Contracts\Validation\Rule;

class ApplicationEnabled implements Rule
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
        $application = Application::where('id', $value)->first();

        if (! $application) {
            return true;
        }

        return $application->enabled == 1;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('messages.rule.application_enabled');
    }
}
