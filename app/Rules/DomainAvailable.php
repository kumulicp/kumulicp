<?php

namespace App\Rules;

use App\Exceptions\DomainRegistrationException;
use App\Support\Facades\Domain;
use App\Tld;
use Illuminate\Contracts\Validation\Rule;

class DomainAvailable implements Rule
{
    protected string $message = 'This domain is unavailable';

    public function __construct() {}

    public function passes($attribute, $value): bool
    {
        $tld_name = Domain::getTld($value);
        $tld = Tld::where('name', $tld_name)->first();

        if (! $tld) {
            $this->message = __('messages.rule.domain_available');

            return false;
        }

        try {
            $domain_response = Domain::registrar($tld)->check($value);

            if (! $domain_response['available']) {
                $this->message = __('messages.rule.domain_available');

                return false;
            }

            return true;
        } catch (DomainRegistrationException $e) {
            $this->message = $e->getMessage();

            return false;
        }
    }

    public function message(): string
    {
        return $this->message;
    }
}
