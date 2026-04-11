<?php

namespace App\Http\Controllers\Account\Organization;

use App\Actions\Organizations\SubscriptionUpdate;
use App\Http\Controllers\Controller;
use App\Jobs\Accounts\UpdateOrganization;
use App\Organization;
use App\Plan;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Settings;
use App\Support\Facades\Subscription;
use App\Support\Organizations;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Suborganization extends Controller
{
    public function index()
    {
        $this->authorize('view-suborganizations');

        $organization = Organization::account();

        $org_types = Plan::whereNot('org_type', '')->where('archive', 0)->groupBy('org_type')->get()->map(function ($plan) {
            $org_types = Organizations::types();

            return [
                'name' => $org_types[$plan->org_type],
                'value' => $plan->org_type,
            ];
        });
        $organizations = $organization->suborganizations()->paginate(15);

        return inertia('Organization/Settings/Organization/SuborganizationsList', [
            'organizations' => $organizations->map(function ($organization, $value) {
                $primary_contact = $organization->primary_contact ? AccountManager::users()->find($organization->primary_contact->username) : null;

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'description' => $organization->description,
                    'email' => $organization->email,
                    'phone_number' => $organization->phone_number,
                    'street' => $organization->street,
                    'zipcode' => $organization->zipcode,
                    'city' => $organization->city,
                    'state' => $organization->state,
                    'country' => $organization->country,
                    'main_contact' => $primary_contact ? [
                        'id' => $primary_contact->attribute('username'),
                        'first_name' => $primary_contact->attribute('first_name'),
                        'last_name' => $primary_contact->attribute('last_name'),
                        'email' => $primary_contact->attribute('email'),
                        'phone_number' => $primary_contact->attribute('phone_number'),
                    ] : [],
                ];
            }),
            'can' => [
                'add_org' => true,
            ],
            'meta' => [
                'total' => $organizations->total(),
                'pages' => $organizations->lastPage(),
                'page' => $organizations->currentPage(),
            ],
            'terms_url' => Settings::get('terms_url'),
            'base_domain' => Settings::get('base_domain'),
            'org_types' => $org_types,
            'breadcrumbs' => [
                [
                    'label' => 'Organization',
                    'url' => '/settings/organization',
                ],
                [
                    'label' => 'Suborganizations',
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('add-suborganization');

        $data = $request->validate([
            'subdomain' => ['required', 'string', 'max:30', 'alpha_num', 'unique:organizations,slug', 'lowercase'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:100'],
            'phone_number' => ['required', 'string', 'max:30'],
            'street' => ['required', 'string', 'max:100'],
            'zipcode' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:nonprofit,business,individual'],
            'terms_of_use' => 'required|accepted',
        ]);

        $organization = Organization::account();
        try {
            // Create organization in database
            $suborganization = new Organization;
            $suborganization->parent_organization_id = $organization->id;
            $suborganization->plan_id = $organization->plan_id;
            $suborganization->slug = $data['subdomain'];
            $suborganization->api_token = Str::random(60);
            $suborganization->name = $data['name'];
            $suborganization->description = $data['description'];
            $suborganization->email = $data['email'];
            $suborganization->phone_number = $data['phone_number'];
            $suborganization->street = $data['street'];
            $suborganization->zipcode = $data['zipcode'];
            $suborganization->city = $data['city'];
            $suborganization->state = $data['state'];
            $suborganization->country = $data['country'];
            $suborganization->secretpw = Str::password(20, true, true, false, false);
            $suborganization->type = $data['type'];
            $suborganization->contact_first_name = $organization->contact_first_name;
            $suborganization->contact_last_name = $organization->contact_last_name;
            $suborganization->contact_email = $organization->contact_email;
            $suborganization->contact_phone_number = $organization->contact_phone_number;
            $suborganization->primary_contact()->associate($organization->primary_contact);
            $suborganization->settings = [
                'step' => 4,
                'include_in_parent_invoice' => true,
            ];
            $suborganization->status = 'active';
            $suborganization->save();

            // Add domain
            $organization_service = OrganizationFacade::setOrganization($suborganization);

        } catch (\Throwable $e) {
            report($e);
            $suborganization->domains()->delete();
            $suborganization->delete();
            throw new \Exception($e->getMessage());
        }

        return redirect('/settings/suborganizations/'.$suborganization->id)->with('success', __('organization.suborganization.created'));
    }

    public function edit(Organization $organization)
    {
        $this->authorize('edit-organization', $organization);

        return inertia('Organization/Settings/Organization/SuborganizationEdit', [
            'org' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'description' => $organization->description,
                'email' => $organization->email,
                'phone_number' => $organization->phone_number,
                'street' => $organization->street,
                'zipcode' => $organization->zipcode,
                'city' => $organization->city,
                'state' => $organization->state,
                'country' => $organization->country,
                'include_in_parent_invoice' => $organization->setting('include_in_parent_invoice'),
                'user_first_name' => $organization->contact_first_name,
                'user_last_name' => $organization->contact_last_name,
                'user_phone_number' => $organization->contact_phone_number,
                'user_email' => $organization->contact_email,
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Organization',
                    'url' => '/settings/organization',
                ],
                [
                    'label' => 'Suborganization',
                    'url' => '/settings/suborganizations',
                ],
                [
                    'label' => $organization->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, Organization $organization)
    {
        $this->authorize('edit-organization', $organization);

        /* Validate */
        $validatedData = $request->validate([
            'name' => 'required',
            'description' => 'required',
            'org_email' => 'required|email|lowercase',
            'org_phone_number' => 'required',
            'street' => 'required',
            'zipcode' => 'required',
            'city' => 'required',
            'state' => 'required',
            'country' => 'required',
            'include_in_parent_invoice' => 'required|boolean',
            'user_first_name' => 'required_if:include_in_parent_invoice,false',
            'user_last_name' => 'required_if:include_in_parent_invoice,false',
            'user_phone_number' => 'required_if:include_in_parent_invoice,false',
            'user_email' => 'required_if:include_in_parent_invoice,false',
        ]);

        $org_name = $request->name;
        $org_description = $request->description;
        $org_email = $request->org_email;
        $org_phone_number = $request->org_phone_number;
        $street = $request->street;
        $zipcode = $request->zipcode;
        $city = $request->city;
        $state = $request->state;
        $country = $request->country;
        $current_include_in_parent_invoice = $organization->setting('include_in_parent_invoice');

        $organization->name = $org_name;
        $organization->description = $org_description;
        $organization->email = $org_email;
        $organization->phone_number = $org_phone_number;
        $organization->street = $street;
        $organization->zipcode = $zipcode;
        $organization->city = $city;
        $organization->state = $state;
        $organization->country = $country;
        $organization->updateSetting('include_in_parent_invoice', $request->include_in_parent_invoice);
        $organization->contact_first_name = $request->user_first_name;
        $organization->contact_last_name = $request->user_last_name;
        $organization->contact_phone_number = $request->user_phone_number;
        $organization->contact_email = $request->user_email;
        $organization->save();

        UpdateOrganization::dispatch($organization);

        if ($current_include_in_parent_invoice !== $organization->setting('include_in_parent_invoice')) {
            Action::execute(new SubscriptionUpdate($organization->parent_organization, Subscription::all()), background: true);
        }

        if (AccountManager::driver() !== 'db') {
            AccountManager::account($organization)->update($validatedData);
        }

        return to_route('settings.suborganizations.edit', ['organization' => $organization->id])->with('success', __('organization.suborganization.updated', ['suborg' => $org_name]));
    }

    public function destroy()
    {
        redirect('/settings/suborganizations')->with('error', 'Suborganizations cannot be deleted yet! :(');
    }
}
