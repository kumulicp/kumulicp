<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Facades\SecurityTool;
use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Http\Request;

class SecurityToolSettings extends Controller
{
    public function index()
    {
        return inertia('Admin/Settings/SecurityToolSettings', [
            'tools' => collect(SecurityTool::all())->map(function ($name) {
                $profile = SecurityTool::profile($name);

                return [
                    'name' => $name,
                    'default_image' => $profile->defaultImage(),
                    'image' => SettingsFacade::get($profile->imageSettingKey()) ?: null,
                ];
            })->values(),
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                ['label' => __('settings.securityTools')],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $tools = SecurityTool::all();

        $validated = $request->validate([
            'images' => 'array',
            'images.*' => 'nullable|string|max:255',
        ]);

        foreach ($tools as $tool) {
            $profile = SecurityTool::profile($tool);
            $value = $validated['images'][$tool] ?? null;

            if ($value) {
                SettingsFacade::update($profile->imageSettingKey(), $value);
            } else {
                SettingsFacade::remove($profile->imageSettingKey());
            }
        }

        return redirect('/admin/settings/security-tools')->with('success', __('admin.settings.updated'));
    }
}
