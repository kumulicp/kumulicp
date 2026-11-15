<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Facades\FastCache;
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

        $before = collect(FeatureFlags::all())->pluck('enabled', 'key');

        foreach (FeatureFlags::keys() as $key) {
            FeatureFlags::set($key, (bool) $validated['features'][$key]);
        }

        // The menus are cached per organization and built from the flags, so
        // drop them now rather than waiting for them to expire
        if (collect(FeatureFlags::keys())->contains(fn ($key) => $before[$key] !== (bool) $validated['features'][$key])) {
            FastCache::clearAll();
        }

        return redirect('/admin/settings/features')->with('success', __('admin.settings.updated'));
    }
}
