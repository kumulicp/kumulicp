<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;

class FeatureSettings extends Controller
{
    public function index()
    {
        return inertia('Admin/Settings/FeatureSettings', [
            'features' => FeatureFlags::all(),
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                ['label' => __('admin.settings.features.features')],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $rules = ['features' => ['required', 'array']];
        foreach (FeatureFlags::keys() as $key) {
            $rules["features.{$key}"] = ['required', 'boolean'];
        }

        $validated = $request->validate($rules);

        foreach (FeatureFlags::keys() as $key) {
            FeatureFlags::set($key, (bool) $validated['features'][$key]);
        }

        return redirect('/admin/settings/features')->with('success', __('admin.settings.updated'));
    }
}
