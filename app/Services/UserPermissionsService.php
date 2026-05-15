<?php

namespace App\Services;

use App\Actions\Organizations\SubscriptionUpdate;
use App\AppRole;
use App\Enums\AccessType;
use App\Events\Users\UserPermissionsUpdated;
use App\Notifications\PermissionsUpdatedNotification;
use App\Notifications\UserCreated;
use App\Organization;
use App\Support\Facades\Action;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Gate;

class UserPermissionsService
{
    /**
     * Update a user's app permissions.
     *
     * @param  mixed  $user  AccountManager user instance
     * @param  string  $userId  Username / user identifier
     * @param  Organization  $organization
     * @param  array  $permissionsInput  Raw permissions array keyed by app instance ID
     * @param  bool  $withSideEffects  Set false to skip action dispatch, events, and notifications (useful in tests)
     */
    public function updatePermissions(
        mixed $user,
        string $user_id,
        Organization $organization,
        array $permissions_input,
        bool $with_side_effects = true,
    ): void {
        $permissions = $user->permissions();

        $processed_permissions = $this->processRequest($permissions_input);

        $this->syncControlPanelAccess($user, $user_id, $permissions, $permissions_input, $organization);

        $app_roles = [];
        $task = null;

        foreach (OrganizationFacade::apps() as $app_instance) {
            FastCache::clear(organization: $app_instance->organization);

            if ($app_instance->status === 'deactivated') {
                continue;
            }

            $additional_storage = new AdditionalStorageService($organization, 'user', $user_id, $app_instance);
            $app_permissions = $processed_permissions[$app_instance->id] ?? [];

            $can_update_standard = Subscription::base()->type === 'package'
                ? Gate::allows('update-standard-user', $user)
                : Gate::allows('update-app-standard-user', [$user, $app_instance]);

            $can_update_basic = Subscription::base()->type === 'package'
                ? Gate::allows('update-basic-user', $user)
                : Gate::allows('update-app-basic-user', [$user, $app_instance]);

            $roles = [];
            foreach ($app_permissions as $role_slug) {
                $role = AppRole::where('application_id', $app_instance->application_id)
                    ->fromAppSlug($app_instance, $role_slug)
                    ->first();

                if (! $role) {
                    continue;
                }

                if ($role->ignore_role) {
                    foreach ($role->implied_roles as $implied_role) {
                        $roles[] = $implied_role;
                        $app_roles[$app_instance->id][] = $implied_role->slug;
                    }
                } else {
                    $app_roles[$app_instance->id][] = $role->slug;
                }

                if (
                    ($role->access_type === AccessType::STANDARD && $can_update_standard) ||
                    ($role->access_type === AccessType::BASIC && $can_update_basic) ||
                    $role->access_type === AccessType::MINIMAL
                ) {
                    $roles[] = $role;
                }
            }

            $permissions->updateAppRoles($app_instance, $roles);

            if (count($roles) === 0 && $additional_storage->quantity() > 0) {
                $additional_storage->delete();
            }

            if ($with_side_effects) {
                $task = Action::dispatch(
                    category: $app_instance->application->slug,
                    action: 'prUserPermissionsServiceocess_permissions',
                    params: [$app_instance, ['permission' => $app_roles, 'user' => $user_id]],
                    parent_task: $task,
                );
                Action::dispatch($app_instance->application->slug, 'process_user_options', [$app_instance, $user, $permissions_input], $task);
            }
        }

        $permissions->updateUserAccessType();

        if (! $with_side_effects || ! $permissions->hasChanges()) {
            return;
        }

        $changes = $permissions->changes();

        Action::execute(new SubscriptionUpdate($organization, Subscription::all()), background: true);
        UserPermissionsUpdated::dispatch($user);

        if ($user->isInitiated()) {
            $user->notify(new PermissionsUpdatedNotification($changes, $user));
        } elseif ($new_user_code = $organization->new_user_codes()->where('username', $user_id)->where('status', 'pending')->first()) {
            $user->notify(new UserCreated($user, $new_user_code->code));
            $new_user_code->status = 'sent';
            $new_user_code->save();
        }
    }

    private function processRequest(array $permissions_input): array
    {
        $array = [];

        foreach ($permissions_input as $app => $permissions) {
            if (is_array($permissions)) {
                foreach ($permissions as $n => $permission) {
                    if ($permission != 'none') {
                        $array[$app][$n] = $permission;
                    }
                }
            } elseif (is_string($permissions)) {
                if ($permissions == 'on') {
                    $array[$app] = true;
                } else {
                    $array[$app] = $permissions;
                }
            } elseif (is_bool($permissions)) {
                $array[$app] = $permissions;
            }
        }

        return $array;
    }

    private function syncControlPanelAccess(mixed $user, string $user_id, mixed $permissions, array $permissions_input, Organization $organization): void
    {
        if (auth()->user()?->username === $user_id) {
            return;
        }

        $organization_access = data_get($permissions_input, 'control_panel.0');
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
            $admin_input = data_get($permissions_input, 'control_panel_admin.0');
            if ($admin_input === 'control_panel_standard' && ! Gate::allows('admin', $user)) {
                $permissions->addControlPanelAdminAccess();
            } elseif ($admin_input === 'none' && Gate::allows('admin', $user)) {
                $permissions->removeControlPanelAdminAccess();
            }
        }
    }
}
