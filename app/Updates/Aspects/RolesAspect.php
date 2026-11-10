<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\AppRole;
use App\Updates\Fingerprint;

/**
 * The roles the app's groups in LDAP are created for: the roles of its version
 * and of the app, which of them its plan gives access to, and the features
 * they depend on.
 */
class RolesAspect extends Aspect
{
    public function key(): string
    {
        return 'roles';
    }

    public function label(): string
    {
        return __('admin.update_aspects.roles');
    }

    public function appliesTo(AppInstance $app_instance): bool
    {
        return config('account_manager.driver') === 'ldap';
    }

    public function fingerprint(AppInstance $app_instance): string
    {
        $plan = $app_instance->plan;

        $roles = AppRole::where('application_id', $app_instance->application_id)
            ->with('implied_roles:id')
            ->orderBy('id')
            ->get()
            ->map(fn (AppRole $role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'access_type' => $role->access_type->value,
                'required_features' => $role->required_features,
                'ignore_role' => $role->ignore_role,
                'implied' => $role->implied_roles->pluck('id')->sort()->values()->all(),
            ])
            ->all();

        return Fingerprint::of([
            'version_roles' => $app_instance->version?->roles,
            'roles' => $roles,
            // Which access types the plan offers decides what a role's group gives
            'plan' => $plan ? ['id' => $plan->id, 'settings' => $plan->settings, 'features' => $plan->features] : null,
            'features' => $app_instance->setting('features'),
        ]);
    }
}
