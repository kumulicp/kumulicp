<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

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
                'aliases' => Settings::get('ldap_aliases'),
                'recovery_email' => Settings::get('ldap_recovery_email'),
                'access_type' => Settings::get('ldap_access_type'),
                'password' => Settings::get('ldap_password'),
            ],
            'ldap_enabled' => LdapEmailAttributes::usingLdap(),
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
        // An LDAP attribute name: a letter, then letters, digits and hyphens
        $new_attribute = ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9-]*$/'];

        /* Validate */
        $validator = Validator::make($request->all(), [
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:100',
            'username' => 'nullable|string|max:100',
            'personal_email' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:100',
            'aliases' => $new_attribute,
            'recovery_email' => $new_attribute,
            'access_type' => 'nullable|string|max:100',
            'password' => 'nullable|string|max:100',
        ]);

        // Aliases and the recovery email each need an attribute no other mapping uses
        $validator->after(function ($validator) {
            foreach (LdapEmailAttributes::conflicts($validator->getData()) as $field => $other) {
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, __('admin.settings.ldap_attribute_not_unique', [
                        'other' => str_replace('_', ' ', $other),
                    ]));
                }
            }
        });

        $validated = $validator->validate();

        Settings::update('ldap_first_name', Arr::get($validated, 'first_name'));
        Settings::update('ldap_last_name', Arr::get($validated, 'last_name'));
        Settings::update('ldap_email', Arr::get($validated, 'email'));
        Settings::update('ldap_phone_number', Arr::get($validated, 'phone_number'));
        Settings::update('ldap_username', Arr::get($validated, 'username'));
        Settings::update('ldap_personal_email', Arr::get($validated, 'personal_email'));
        Settings::update('ldap_name', Arr::get($validated, 'name'));
        Settings::update('ldap_aliases', Arr::get($validated, 'aliases'));
        Settings::update('ldap_recovery_email', Arr::get($validated, 'recovery_email'));
        Settings::update('ldap_access_type', Arr::get($validated, 'access_type'));
        Settings::update('ldap_password', Arr::get($validated, 'password'));

        return redirect('admin/settings/ldap')->with('success', __('admin.settings.updated'));
    }
}
