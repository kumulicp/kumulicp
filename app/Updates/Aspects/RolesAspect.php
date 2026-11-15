<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\AppRole;
use App\Updates\AspectInputs;
use App\Updates\Fingerprint;

/**
 * The roles the app's groups in LDAP are created for: the roles of its version
 * and of the app, and the features they depend on. Nothing of the plan: the
 * groups are made from the roles alone.
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
            // Whether a role's features are on decides if its group is made
            'features' => AspectInputs::featureState($app_instance, collect($roles)->pluck('required_features')->flatten()->filter()->unique()->sort()->values()->all()),
        ]);
    }
}
