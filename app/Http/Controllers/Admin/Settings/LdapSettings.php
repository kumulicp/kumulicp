<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Facades\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class LdapSettings extends Controller
{
    public function index()
    {
        return inertia()->render('Admin/Settings/LdapSettings', [
            'settings' => [
                'first_name' => Settings::get('ldap_first_name'),
                'last_name' => Settings::get('ldap_last_name'),
                'email' => Settings::get('ldap_email'),
                'phone_number' => Settings::get('ldap_phone_number'),
                'username' => Settings::get('ldap_username'),
                'personal_email' => Settings::get('ldap_personal_email'),
                'name' => Settings::get('ldap_name'),
                'org_email' => Settings::get('ldap_org_email'),
                'access_type' => Settings::get('ldap_access_type'),
                'password' => Settings::get('ldap_password'),
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                ['label' => __('admin.settings.ldap_settings')],
            ],
        ]);
    }

    public function update(Request $request)
    {

        /* Validate */
        $validated = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:100',
            'username' => 'nullable|string|max:100',
            'personal_email' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:100',
            'org_email' => 'nullable|string|max:100',
            'access_type' => 'nullable|string|max:100',
            'password' => 'nullable|string|max:100',
        ]);

        Settings::update('ldap_first_name', Arr::get($validated, 'first_name'));
        Settings::update('ldap_last_name', Arr::get($validated, 'last_name'));
        Settings::update('ldap_email', Arr::get($validated, 'email'));
        Settings::update('ldap_phone_number', Arr::get($validated, 'phone_number'));
        Settings::update('ldap_username', Arr::get($validated, 'username'));
        Settings::update('ldap_personal_email', Arr::get($validated, 'personal_email'));
        Settings::update('ldap_name', Arr::get($validated, 'name'));
        Settings::update('ldap_org_email', Arr::get($validated, 'org_email'));
        Settings::update('ldap_access_type', Arr::get($validated, 'access_type'));
        Settings::update('ldap_password', Arr::get($validated, 'password'));

        return redirect('admin/settings/ldap')->with('success', __('admin.settings.updated'));
    }
}
