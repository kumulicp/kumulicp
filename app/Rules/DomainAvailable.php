<?php

namespace App\Rules;

use App\Integrations\Registrars\Namecheap\API\Domains;
use Illuminate\Contracts\Validation\Rule;

class DomainAvailable implements Rule
{
    protected $message = 'This domain is unavailable';

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
        $domains = new Domains($organization);
        $domain_response = $domains->check($value);

        if ($domains->hasError()) {
            foreach ($domains->error('array') as $error) {
                if ($error['Number'] == 2030280) {
                    $this->message = __('messages.rule.domain_available');

                    return false;
                }
            }
        }

        return $domain_response['Available'];
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return $this->message;
    }
}
