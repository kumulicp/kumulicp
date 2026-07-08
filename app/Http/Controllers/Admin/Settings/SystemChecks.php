<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\SystemChecks\LdapModelValidation;

class SystemChecks extends Controller
{
    public function index()
    {
        return inertia()->render('Admin/Settings/SystemChecks', [
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function ldapModels()
    {
        return inertia()->render('Admin/Settings/SystemChecks', [
            'breadcrumbs' => $this->breadcrumbs(),
            'ldap_model_results' => [
                'issues' => (new LdapModelValidation)->run(),
            ],
        ]);
    }

    public function correctLdapModels()
    {
        $check = new LdapModelValidation;

        $corrections = array_map(
            fn (array $issue) => $check->attemptFix($issue['dn']),
            $check->run(),
        );

        return inertia()->render('Admin/Settings/SystemChecks', [
            'breadcrumbs' => $this->breadcrumbs(),
            'ldap_model_results' => [
                'issues' => $check->run(),
                'corrections' => $corrections,
            ],
        ]);
    }

    private function breadcrumbs()
    {
        return [
            [
                'label' => __('admin.settings.control_panel_settings'),
                'url' => '/admin/settings',
            ],
            ['label' => __('admin.settings.system_checks_settings')],
        ];
    }
}
