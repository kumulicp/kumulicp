<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Organization;
use App\Services\UserPermissionsService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class Permissions extends Controller
{
    public function edit($userid)
    {
        $user = AccountManager::users()->find($userid);

        Gate::authorize('edit-user', $user);

        $username = $user->attribute('username');
        $name = $user->attribute('name');

        return inertia('Organization/Users/UserPermissions', [
            'permissions' => $user->permissions()->get(),
            'user' => [
                'id' => $username,
                'name' => $name,
                'access_type' => $user->userAccessType(),
                'can' => [
                    'change_access_type' => Auth::user()->username !== $username,
                ],
            ],
            'access_types' => Subscription::base()->availableAccessTypesForUser($user),
            'plan' => [
                'type' => Subscription::base()->type,
                'payment_enabled' => (bool) Subscription::base()->payment_enabled,
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Users',
                    'url' => '/users',
                ],
                [
                    'label' => $name,
                ],
            ],
        ]);
    }

    public function update(Request $request, $userid)
    {
        $user = AccountManager::users()->find($userid);
        $organization = auth()->user()->organization;

        Gate::authorize('edit-user', $user);

        $request->validate([
            'permission.control_panel.0' => [
                function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                    if ($value === 'none') {
                        return;
                    } elseif ($org_access = Organization::find($value)) {
                        if ($org_access->is($organization) || $org_access->parent_organization?->is($organization)) {
                            return true;
                        }
                    }

                    return $fail('This organization ');
                },
            ],
        ]);

        $permissions_input = $request->input('permission', []);

        app(UserPermissionsService::class)->updatePermissions($user, $userid, $organization, $permissions_input);

        return redirect('/users/'.$userid)->with('success', __('organization.user.permissions.updated', ['user' => $user->attribute('first_name')]));
    }
}
