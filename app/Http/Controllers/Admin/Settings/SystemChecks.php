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
            'ldap_model_results' => (new LdapModelValidation)->run(),
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
