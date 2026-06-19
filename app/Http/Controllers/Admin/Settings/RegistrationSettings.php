<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Http\Request;

class RegistrationSettings extends Controller
{
    public function index()
    {
        return inertia('Admin/Settings/RegistrationSettings', [
            'settings' => [
                'captcha_provider' => SettingsFacade::get('captcha_provider'),
                'captcha_site_key' => SettingsFacade::get('captcha_site_key'),
                'captcha_secret_key' => SettingsFacade::get('captcha_secret_key'),
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                ['label' => __('admin.settings.registration_settings')],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'captcha_provider' => 'nullable|in:turnstile,hcaptcha',
            'captcha_site_key' => 'nullable|string|max:255',
            'captcha_secret_key' => 'nullable|string|max:255',
        ]);

        SettingsFacade::update('captcha_provider', $validated['captcha_provider'] ?? null);
        SettingsFacade::update('captcha_site_key', $validated['captcha_site_key'] ?? null);
        SettingsFacade::update('captcha_secret_key', $validated['captcha_secret_key'] ?? null);

        return redirect('/admin/settings/registration')->with('success', __('admin.settings.updated'));
    }
}
