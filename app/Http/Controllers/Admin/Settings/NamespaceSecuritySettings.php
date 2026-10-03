<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Plan;
use App\Support\Facades\Settings as SettingsFacade;
use App\Support\Security\NamespaceSecurityPolicy;
use App\Support\Security\SecurityTier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin-defined Pod Security tiers, on top of the built-in presets. Which
 * tier a namespace actually gets is chosen per base plan (see Admin\Plans).
 */
class NamespaceSecuritySettings extends Controller
{
    public function index()
    {
        return inertia('Admin/Settings/NamespaceSecuritySettings', [
            'presets' => collect(SecurityTier::presets())->map(fn (SecurityTier $tier) => $this->present($tier))->values(),
            'tiers' => collect(NamespaceSecurityPolicy::customTiers())->map(fn (SecurityTier $tier) => $this->present($tier))->values(),
            'levels' => SecurityTier::LEVELS,
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                ['label' => __('admin.namespace_security.title')],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $level = ['nullable', 'string', Rule::in(SecurityTier::LEVELS)];
        $version = ['nullable', 'string', 'regex:/^(latest|v1\.\d{1,3})$/'];

        $validated = $request->validate([
            'tiers' => 'array|max:20',
            'tiers.*.key' => [
                'required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/', 'distinct',
                Rule::notIn(array_keys(SecurityTier::presets())),
            ],
            'tiers.*.label' => 'nullable|string|max:60',
            'tiers.*.enforce' => $level,
            'tiers.*.warn' => $level,
            'tiers.*.audit' => $level,
            'tiers.*.enforce_version' => $version,
            'tiers.*.warn_version' => $version,
            'tiers.*.audit_version' => $version,
        ]);

        $tiers = [];
        foreach ($validated['tiers'] ?? [] as $tier) {
            $tiers[$tier['key']] = SecurityTier::fromArray($tier['key'], $tier)->toArray();
        }

        $this->ensureRemovedTiersUnused(array_keys($tiers));

        if ($tiers === []) {
            SettingsFacade::remove(NamespaceSecurityPolicy::TIERS_SETTING);
        } else {
            SettingsFacade::update(NamespaceSecurityPolicy::TIERS_SETTING, json_encode($tiers));
        }

        return redirect('/admin/settings/namespace-security')->with('success', __('admin.namespace_security.updated'));
    }

    // Removing a tier a plan still selects would silently drop that plan's
    // namespaces back to "no policy" on the next sync
    private function ensureRemovedTiersUnused(array $kept): void
    {
        foreach (Plan::all() as $plan) {
            $key = $plan->setting('security.tier');

            if ($key && ! isset(SecurityTier::presets()[$key]) && ! in_array($key, $kept, true)) {
                throw ValidationException::withMessages([
                    'tiers' => __('admin.namespace_security.tier_in_use', ['tier' => $key, 'plan' => $plan->name]),
                ]);
            }
        }
    }

    private function present(SecurityTier $tier): array
    {
        return ['key' => $tier->key, 'preset' => $tier->preset] + $tier->toArray();
    }
}
