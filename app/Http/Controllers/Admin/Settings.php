<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Settings extends Controller
{
    public function index()
    {
        return inertia()->render('Admin/Settings/ControlPanelSettings', [
            'settings' => [
                'base_domain' => SettingsFacade::get('base_domain'),
                'terms_url' => SettingsFacade::get('terms_url'),
                'docs_url' => SettingsFacade::get('docs_url'),
                'primary_color' => SettingsFacade::get('primary_color'),
                'secondary_color' => SettingsFacade::get('secondary_color'),
                'welcome_page' => SettingsFacade::get('welcome_page'),
                'support_email' => SettingsFacade::get('support_email'),
                'error_email' => SettingsFacade::get('error_email'),
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                ],
            ],
        ]);
    }

    public function update(Request $request)
    {
        /* Validate */
        $validated = $request->validate([
            'base_domain' => 'string|max:100|required',
            'terms_url' => 'nullable|string|max:255',
            'docs_url' => 'nullable|string|max:255',
            'welcome_page' => 'string|nullable',
            'primary_color' => 'nullable|string|max:10',
            'secondary_color' => 'nullable|string|max:10',
            'support_email' => 'nullable|email|max:100',
            'error_email' => 'nullable|email|max:100',
        ]);

        SettingsFacade::update('base_domain', $validated['base_domain']);
        SettingsFacade::update('terms_url', $validated['terms_url']);
        SettingsFacade::update('docs_url', $validated['docs_url']);
        SettingsFacade::update('welcome_page', $validated['welcome_page']);
        SettingsFacade::update('primary_color', $validated['primary_color']);
        SettingsFacade::update('secondary_color', $validated['secondary_color']);
        SettingsFacade::update('support_email', Arr::get($validated, 'support_email'));
        SettingsFacade::update('error_email', Arr::get($validated, 'error_email'));

        return redirect('admin/settings')->with('success', __('admin.settings.updated'));
    }
}
