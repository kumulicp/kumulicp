<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationSettings extends Controller
{
    public function index()
    {
        return inertia('Admin/Settings/RegistrationSettings', [
            'settings' => [
                'captcha_provider' => SettingsFacade::get('captcha_provider'),
                'captcha_site_key' => SettingsFacade::get('captcha_site_key'),
                // The secret never goes back to the browser: the page only learns whether one is stored
                'captcha_secret_key_set' => filled(SettingsFacade::get('captcha_secret_key')),
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
        $has_secret = filled(SettingsFacade::get('captcha_secret_key'));

        // With a provider chosen the widget needs its site key and the check needs the secret: without
        // either, every registration would fail
        $validated = $request->validate([
            'captcha_provider' => 'nullable|in:turnstile,hcaptcha',
            'captcha_site_key' => ['nullable', 'required_with:captcha_provider', 'string', 'max:255'],
            'captcha_secret_key' => [
                'nullable',
                Rule::requiredIf(fn () => $request->filled('captcha_provider') && ! $has_secret),
                'string',
                'max:255',
            ],
        ]);

        $provider = $validated['captcha_provider'] ?? null;

        SettingsFacade::update('captcha_provider', $provider);
        SettingsFacade::update('captcha_site_key', $provider ? $validated['captcha_site_key'] : null);

        if (! $provider) {
            SettingsFacade::update('captcha_secret_key', null);
        } elseif (filled($validated['captcha_secret_key'] ?? null)) {
            // Left blank, the stored secret stays
            SettingsFacade::update('captcha_secret_key', $validated['captcha_secret_key']);
        }

        return redirect('/admin/settings/registration')->with('success', __('admin.settings.updated'));
    }
}
