<?php

namespace App\Http\Controllers\Account\Web;

use App\Actions\Domains\RegisterDomainName;
use App\Exceptions\DomainRegistrationException;
use App\Http\Controllers\Controller;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\DomainAvailable;
use App\Rules\DomainName;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Tld;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class Register extends Controller
{
    public function setup($domain_name)
    {
        $this->authorize('register-domains');

        $organization = auth()->user()->organization;

        $domain = OrgDomain::where('organization_id', $organization->id)
            ->where('name', $domain_name)
            ->first();

        // Check if
        if (! $domain) {
            return redirect('/settings/domains/availability');
        }

        $tld = $domain->tld;
        if ($tld->registration_disabled || ! $tld->is_api_registerable) {
            return redirect('/settings/domains/availability')->with('error', __('organization.domain.denied.tld', ['tld' => $tld->name]));
        }

        $registrar = Domain::registrar($tld);
        $domain_check = $registrar->check($domain_name);
        $is_premium = $registrar->pricing($tld, $domain_name)->isPremium();

        $registrar_prices = $registrar->pricing($tld, $domain_name)->registrationPrices();
        if (! $is_premium && (float) $tld->standard_price > 0) {
            $price = (float) $tld->standard_price;
            $prices = [];
            foreach ($registrar_prices as $year => $registration_price) {
                $prices[$year] = $price;
            }
        } else {
            $prices = $registrar_prices;
            $price = $registrar->pricing($tld, $domain_name)->isPremium() ? $registrar->pricing($tld, $domain_name)->premiumPrice() : $registrar->pricing($tld, $domain_name)->registrationPrice();
        }

        $user = Auth::user();

        if (! $domain_check['available']) {
            return redirect('/settings/domains/availability')->with('error', __('organization.domain.denied.unavailable'));
        }

        return inertia('Organization/Settings/WebDomains/WebDomainsNewRegister', [
            'domain' => [
                'name' => $domain->name,
            ],
            'user' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'email' => $user->email,
            ],
            'organization' => [
                'name' => $organization->name,
                'email' => $organization->email,
                'phone_number' => $organization->phone_number,
                'address_1' => $organization->street,
                'address_2' => '',
                'city' => $organization->city,
                'postal_code' => $organization->zipcode,
                'state' => $organization->state,
                'country' => $organization->country,
            ],
            'standard_prices' => $prices,
            'registration_price' => $price,
            'is_premium' => $is_premium,
            'tld' => $tld->name,
            'breadcrumbs' => [
                [
                    'url' => '/settings/domains',
                    'label' => 'Domains',
                ],
                [
                    'url' => '/settings/domains/availability',
                    'label' => 'Availability',
                ],
                [
                    'label' => $domain->name.' Registration',
                ],
            ],
        ]);
    }

    public function availability()
    {
        $this->authorize('register-domains');

        return inertia('Organization/Settings/WebDomains/WebDomainsNewAvailability', [
            'breadcrumbs' => [
                [
                    'url' => '/settings/domains',
                    'label' => 'Domains',
                ],
                [
                    'label' => 'Check Availability',
                ],
            ],
        ]);
    }

    public function select(Request $request)
    {
        $this->authorize('register-domains');

        $validated = $request->validate([
            'domain_name' => ['string', 'max:70', 'required', 'lowercase', new DomainAvailable], // TODO: Put checks here(organization owns domain, domain is available, registerable at control panel
        ]);

        $organization = auth()->user()->organization;
        $org_domain = OrgDomain::where('organization_id', $organization->id)
            ->where('name', $validated['domain_name'])
            ->where('status', '!=', 'pending_registration')
            ->first();

        if ($org_domain) {
            return redirect('/settings/domains')->with('error', __('organization.domain.denied.own', ['domain' => $org_domain->name]));
        }

        $tld_name = Domain::getTld($validated['domain_name']);
        $tld = Tld::where('name', $tld_name)->first();
        $domain_response = Domain::registrar($tld)->check($validated['domain_name']);

        $domain = OrgDomain::where('organization_id', $organization->id)
            ->where('status', 'pending_registration')
            ->first();

        if (! $domain) {
            $domain = new OrgDomain;
            $domain->organization_id = $organization->id;
        }

        $domain->name = $validated['domain_name'];
        $domain->is_premium = $domain_response['is_premium_name'];
        $domain->icann_fee = $domain_response['ican_fee'];
        $domain->premium_registration_price = $domain_response['premium_registration_price'];
        $domain->premium_renewal_price = $domain_response['premium_renewal_price'];
        $domain->premium_restore_price = $domain_response['premium_restore_price'];
        $domain->premium_transfer_price = $domain_response['premium_transfer_price'];
        $domain->status = 'pending_registration';
        $domain->source = $tld->default_driver;
        $domain->type = 'managed';
        $domain->tld_id = $tld->id;
        $domain->save();

        return redirect('/settings/domains/register/'.$validated['domain_name']);
    }

    public function check(Request $request)
    {
        $this->authorize('register-domains');

        $validated = $request->validate([
            'domain_name' => ['string', 'max:200', 'required', 'lowercase', new DomainName], // TODO: Check whether this is truly a domain name
        ]);

        $organization = auth()->user()->organization;

        $domain_tld = Domain::getTld($validated['domain_name']);

        $tld = Tld::where('name', $domain_tld)->first();

        $message = '';
        $price = ($tld && (float) $tld->standard_price > 0) ? (float) $tld->standard_price : null;

        if (! $tld) {
            $message = "{$domain_tld} is not available. Please try another one.";
            $availability = false;
        } elseif ($tld->registration_disabled || ! $tld->is_api_registerable) {
            $message = __('organization.domain.denied.cannont_register', ['domain' => $validated['domain_name']]);
            $availability = false;
        } else {
            try {
                $domain_response = Domain::registrar($tld)->check($validated['domain_name']);
                $availability = $domain_response['available'];
                if (! $availability) {
                    throw new DomainRegistrationException($validated['domain_name'].' is not available for registration.');
                } else {
                    $message = $validated['domain_name'].' is available!';

                    if (Arr::get($domain_response, 'is_premium_name', false) || ! $price) {
                        $domain_pricing = Domain::registrar($tld)->pricing($tld, $validated['domain_name']);
                        $price = $domain_pricing->isPremium() ? $domain_pricing->premiumPrice() : $domain_pricing->registrationPrice();
                    }
                }
            } catch (DomainRegistrationException $e) {
                $availability = false;
                $message = $e->getMessage();
            }
        }

        return response()->json([
            'availability' => $availability,
            'message' => $message,
            'price' => $price,
        ]);
    }

    public function register(Request $request, $domain_name)
    {
        $this->authorize('register-domains');

        $validated = $request->validate([
            'years' => 'integer|min:1|max:10|required',
            'organization_name' => 'string|max:100|required',
            'email_address' => 'email|max:128|required',
            'first_name' => 'string|max:60|required',
            'last_name' => 'string|max:60|required',
            'accept_terms' => 'boolean|required|accepted',
            'address_1' => 'string|max:60|required',
            'address_2' => 'string|max:60|nullable',
            'city' => 'string|max:60|required',
            'state' => 'string|max:60|required',
            'postal_code' => 'string|max:15|required',
            'country' => 'string|max:2|required',
            'phone' => 'string|max:20|required',
            'country_phone_code' => 'required|string|max:6',
            'tld' => 'string|required|max:8',
            'cira_legal_type' => 'required_if:tld,ca|nullable|string|in:CCO,CCT,RES,GOV,EDU,ASS,HOP,PRT,TDM,TRD,PLT,LAM,TRS,ABO,INB,LGR,OMK,MAJ',
            'cira_language' => 'required_if:tld,ca|nullable|string|max:2|in:en,fr',
        ]);

        $organization = auth()->user()->organization;

        $org_domain = $organization->domains()
            ->where('name', $domain_name)
            ->where('status', 'pending_registration')
            ->first();
        $domain = Domain::registrar($org_domain);
        $extended_attributes = $domain->extendedAttributes($validated);

        $org_domain->is_managed = true;
        $org_domain->organization_name = $validated['organization_name'];
        $org_domain->email_address = $validated['email_address'];
        $org_domain->first_name = $validated['first_name'];
        $org_domain->last_name = $validated['last_name'];
        $org_domain->address_1 = $validated['address_1'];
        $org_domain->address_2 = $validated['address_2'];
        $org_domain->city = $validated['city'];
        $org_domain->state_province = $validated['state'];
        $org_domain->postal_code = $validated['postal_code'];
        $org_domain->country = $validated['country'];
        $org_domain->country_phone_code = $validated['country_phone_code'];
        $org_domain->phone = $validated['phone'];
        $org_domain->type = 'managed';
        $org_domain->status = 'registering';
        $org_domain->source = $org_domain->tld?->default_driver ?? config('domains.default');
        $org_domain->save();

        $subdomain = new OrgSubdomain;
        $subdomain->domain()->associate($org_domain);
        $subdomain->host = '*';
        $subdomain->name = $domain_name;
        $subdomain->type = 'A';
        $subdomain->ttl = 1800;
        $subdomain->save();

        $subdomain2 = $subdomain->replicate()->fill([
            'host' => '@',
        ]);

        $domain_pricing = $domain->pricing($validated['tld'], $domain_name);
        $tld = Tld::where('name', $validated['tld'])->first();
        // If domain is a premium domain, using premium pricing. If there isn't a custom price set, use registrars price.
        $price = $domain_pricing->isPremium() ? $domain_pricing->premiumPrice() : ((float) $tld->standard_price > 0 ? (float) $tld->standard_price * $validated['years'] : $domain_pricing->registrationPrice($validated['years']));

        $task = Action::execute(new RegisterDomainName($organization, $org_domain, $price, $validated['years'], $extended_attributes));

        return redirect('/settings/domains')->with('success', __('organization.domain.registering', ['domain' => $org_domain->name]));
    }
}
