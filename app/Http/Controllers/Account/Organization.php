<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Jobs\Accounts\UpdateOrganization;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;

class Organization extends Controller
{
    public function index()
    {
        $organization = auth()->user()->organization;
        $users = $organization->users->map(function ($user) {
            return [
                'value' => $user->username,
                'text' => $user->name,
            ];
        });

        $primary_contact = $organization->primary_contact ? AccountManager::users()->find($organization->primary_contact->username) : null;

        return inertia('Organization/Settings/Organization/OrganizationSettings', [
            'org' => [
                'name' => $organization->name,
                'description' => $organization->description,
                'email' => $organization->email,
                'phone_number' => $organization->phone_number,
                'street' => $organization->street,
                'zipcode' => $organization->zipcode,
                'city' => $organization->city,
                'state' => $organization->state,
                'country' => $organization->country,
                'main_contact' => $organization ? [
                    'first_name' => $organization->contact_first_name,
                    'last_name' => $organization->contact_last_name,
                    'email' => $organization->contact_email,
                    'phone_number' => $organization->contact_phone_number,
                ] : [],
            ],
            'users' => $users,
            'breadcrumbs' => [
                [
                    'label' => 'Organization Settings',
                ],
            ],
        ]);
    }

    public function update(Request $request)
    {
        /* Validate */
        $validatedData = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'required|string|max:255',
            'org_email' => 'required|email|lowercase|max:100',
            'org_phone_number' => 'required|max:100',
            'user_first_name' => 'required|max:100',
            'user_last_name' => 'required|max:100',
            'user_email' => 'required|email|lowercase|max:100',
            'user_phone_number' => 'required|max:100',
            'street' => 'required|string|max:100',
            'zipcode' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'country' => 'required|string|max:100',
        ]);
        $organization = auth()->user()->organization;

        $org_name = $request->name;
        $org_description = $request->description;
        $org_email = $request->org_email;
        $org_phone_number = $request->org_phone_number;
        $user_first_name = $request->user_first_name;
        $user_last_name = $request->user_last_name;
        $user_email = $request->user_email;
        $user_phone_number = $request->user_phone_number;
        $street = $request->street;
        $zipcode = $request->zipcode;
        $city = $request->city;
        $state = $request->state;
        $country = $request->country;

        $organization->name = $org_name;
        $organization->description = $org_description;
        $organization->email = $org_email;
        $organization->phone_number = $org_phone_number;
        $organization->contact_first_name = $user_first_name;
        $organization->contact_last_name = $user_last_name;
        $organization->contact_email = $user_email;
        $organization->contact_phone_number = $user_phone_number;
        $organization->street = $street;
        $organization->zipcode = $zipcode;
        $organization->city = $city;
        $organization->state = $state;
        $organization->country = $country;
        $organization->save();

        UpdateOrganization::dispatch($organization);

        if (AccountManager::driver() != 'direct') {
            AccountManager::account($organization)->update($validatedData);
        }

        return redirect('/settings/organization')->with('success', __('organization.updated'));
    }
}
