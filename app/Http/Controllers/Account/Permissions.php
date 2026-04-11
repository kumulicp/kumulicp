<?php

namespace App\Http\Controllers\Account;

use App\Actions\Organizations\SubscriptionUpdate;
use App\AppRole;
use App\Enums\AccessType;
use App\Events\Users\UserPermissionsUpdated;
use App\Http\Controllers\Controller;
use App\Notifications\PermissionsUpdatedNotification;
use App\Notifications\UserCreated;
use App\Organization;
use App\Services\AdditionalStorageService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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
        $data = $request->validate([
            'permission.control_panel.0' => [
                function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                    if ($value === 'none') {
                        return;
                    } elseif ($org_access = Organization::find($value)) {
                        if ($org_access->is($organization)) {
                            return true;
                        } else {
                            if ($org_access->parent_organization()->is($organization)) {
                                return true;
                            }
                        }
                    }

                    return $fail('This organization ');
                },
            ],
        ]);

        $permissions = $user->permissions();
        $changes['detached'] = [];
        $changes['attached'] = [];

        $request_permissions = $request->input('permission') ? $request->input('permission') : [];

        $processed_permissions = $permissions->processRequest($request_permissions);

        // Add user to Control Panel organization group
        if (Auth::user()->username != $user->attribute('username')) {
            $organization_access = $request->input('permission.control_panel.0');
            $organization_give_access = is_int($organization_access) ? Organization::find($organization_access) : null;
            $control_panel_access = $permissions->hasControlPanelAccess();
            if ($organization_give_access && ! $control_panel_access) {
                $permissions->addControlPanelAccess(organization: $organization_give_access);
            } elseif ($organization_give_access && $control_panel_access && $user->databaseUser()?->organization_id !== $organization_access) {
                $user->databaseUser()?->organization()->associate($organization_give_access)->save();
            } elseif (! $organization_give_access && $control_panel_access) {
                $permissions->removeControlPanelAccess();
            }
            if (Gate::allows('admin')) {
                if ($request->input('permission.control_panel_admin.0') === 'control_panel_standard' && ! Gate::allows('admin', $user)) {
                    $permissions->addControlPanelAdminAccess();
                } elseif ($request->input('permission.control_panel_admin.0') === 'none' && Gate::allows('admin', $user)) {
                    $permissions->removeControlPanelAdminAccess();
                }
            }
        }
        $task = null;

        $app_roles = [];
        foreach (OrganizationFacade::apps() as $app_instance) {
            FastCache::clear($app_instance->organization);

            if ($app_instance->status === 'deactivated') {
                continue;
            }

            $additional_storage = new AdditionalStorageService($organization, 'user', $userid, $app_instance);
            $app_permissions = [];
            if (array_key_exists($app_instance->id, $processed_permissions)) {
                $app_permissions = $processed_permissions[$app_instance->id];
            }
            $can_update_app_standard_user = Subscription::base()->type === 'package' ? Gate::allows('update-standard-user', $user) : Gate::allows('update-app-standard-user', [$user, $app_instance]);
            $can_update_app_basic_user = Subscription::base()->type === 'package' ? Gate::allows('update-basic-user', $user) : Gate::allows('update-app-basic-user', [$user, $app_instance]);
            $roles = [];
            foreach ($app_permissions as $role) {
                if ($role = AppRole::where('application_id', $app_instance->application_id)->fromAppSlug($app_instance, $role)->first()) {
                    if ($role->ignore_role) {
                        foreach ($role->implied_roles as $implied_role) {
                            $roles[] = $implied_role;
                            $app_roles[$app_instance->id][] = $implied_role->slug;
                        }
                    } else {
                        $app_roles[$app_instance->id][] = $role->slug;
                    }

                    if (($role->access_type === AccessType::STANDARD && $can_update_app_standard_user) || ($role->access_type === AccessType::BASIC && $can_update_app_basic_user) || $role->access_type === AccessType::MINIMAL) {
                        $roles[] = $role;
                    }
                }
            }

            $permissions->updateAppRoles($app_instance, $roles);

            if (count($roles) === 0 && $additional_storage->quantity() > 0) {
                $additional_storage->delete();
            }

            if ($app_instance->status === 'deactivated') {
                continue;
            }
            $task = Action::dispatch(
                category: $app_instance->application->slug,
                action: 'process_permissions',
                params: [$app_instance, [
                    'permission' => $app_roles,
                    'user' => $userid,
                ]],
                parent_task: $task);
            Action::dispatch($app_instance->application->slug, 'process_user_options', [$app_instance, $user, $request_permissions], $task);
        }

        $permissions->updateUserAccessType();

        $changes = $permissions->changes();

        if ($permissions->hasChanges()) {
            Action::execute(new SubscriptionUpdate($organization, Subscription::all()), background: true);
            // An event that lets apps be aware of updates so that they can run special functions
            UserPermissionsUpdated::dispatch($user);

            if ($user->isInitiated()) {
                $user->notify(new PermissionsUpdatedNotification($changes, $user));
            } elseif ($new_user_code = $organization->new_user_codes()->where('username', $userid)->where('status', 'pending')->first()) {
                $user->notify(new UserCreated($user, $new_user_code->code));
                $new_user_code->status = 'sent';
                $new_user_code->save();
            }
        }

        return redirect('/users/'.$userid)->with('success', __('organization.user.permissions.updated', ['user' => $user->attribute('first_name')]));
    }
}
