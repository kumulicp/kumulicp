<?php

namespace App\Http\Controllers\Account\Web;

use App\Http\Controllers\Controller;
use App\OrgDomain;
use App\Rules\DomainName;
use App\Support\Facades\Domain;
use Illuminate\Http\Request;

class Connect extends Controller
{
    public function setup()
    {
        $this->authorize('add-domains');

        $organization = auth()->user()->organization;

        return inertia('Organization/Settings/WebDomains/WebDomainsNewConnection');
    }

    public function add(Request $request)
    {
        $this->authorize('add-domains');

        /* Validate */
        $validated = $request->validate([
            'domain_name' => [
                'max:100',
                'required',
                'string',
                'lowercase',
                'unique:org_domains,name',
                new DomainName,
                function (string $attribute, mixed $value, \Closure $fail) {
                    // Checks if DNS values exist. If not, the domain probably isn't actually registered
                    if (! checkdnsrr($value, 'A') && ! checkdnsrr($value, 'AAAA')) {
                        $fail(__('organization.domain.denied.unregistered', ['domain' => $value]));
                    }
                },
            ],
        ]);

        $organization = auth()->user()->organization;
        $domain_name = $validated['domain_name'];

        $org_domain = OrgDomain::where('name', $domain_name)
            ->first();

        if ($org_domain) {
            return redirect('/settings/domains')->where('error', __('organization.domain.denied.registered', ['domain' => $org_domain->name]));
        }

        $domain = Domain::add(organization: $organization, name: $domain_name, source: 'organization', type: 'connection', status: 'active');

        return redirect('/settings/domains')->with('success', __('organization.domain.registered', ['domain' => $domain_name]));
    }
}
