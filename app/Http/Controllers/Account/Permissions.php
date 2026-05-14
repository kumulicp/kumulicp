<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Organization;
use App\Services\UserPermissionsService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Permissions extends Controller
{
    public function edit($userid)
    {
        $user = AccountManager::users()->find($userid);

        $this->authorize('edit-user', $user);

        $organization = Organization::account();

        $organizations = collect([$organization])->merge($organization->suborganizations);

        $user_access_types = Subscription::base()->availableAccessTypesForUser($user);

        return inertia('Organization/Users/UserPermissions', [
            'permissions' => $user->permissions()->get(),
            'user' => [
                'id' => $user->attribute('username'),
                'name' => $user->attribute('name'),
                'first_name' => $user->attribute('first_name'),
                'last_name' => $user->attribute('last_name'),
                'phone_number' => $user->attribute('phone_number'),
                'personal_email' => $user->attribute('email'),
                'org_email' => $user->attribute('org_email'),
                'access_type' => $user->userAccessType(),
                'url' => [
                    'show' => '/users/'.$user->attribute('username'),
                    'edit' => '/users/'.$user->attribute('username').'/edit',
                    'permissions' => '/users/'.$user->attribute('username').'/permissions',
                ],
                'can' => [
                    'change_access_type' => Auth::user()->username !== $user->attribute('username'),
                ],
            ],
            'access_types' => $user_access_types,
            'plan' => [
                'type' => Subscription::base()->type,
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Users',
                    'url' => '/users',
                ],
                [
                    'label' => $user->attribute('name'),
                ],
            ],
        ]);
    }

    public function update(Request $request, $userid)
    {
        $user = AccountManager::users()->find($userid);
        $organization = auth()->user()->organization;

        $this->authorize('edit-user', $user);

        $request->validate([
            'permission.control_panel.0' => [
                function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                    if ($value === 'none') {
                        return;
                    } elseif ($org_access = Organization::find($value)) {
                        if ($org_access->is($organization) || $org_access->parent_organization()->is($organization)) {
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
