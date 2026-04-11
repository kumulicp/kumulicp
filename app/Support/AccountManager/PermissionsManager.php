<?php

namespace App\Support\AccountManager;

use App\AppInstance;
use App\AppRole;
use App\Support\Facades\Application;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use App\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PermissionsManager
{
    private $add_roles = [];

    private $remove_roles = [];

    public $changes = [];

    private $organization;

    public function get()
    {
        $permissions = [];
        if (Auth::user()->username != $this->user->attribute('username')) {
            $permissions['control_panel'] = $this->getControlPanelAccess([
                'id' => 'control_panel',
                'name' => 'Control Panel',
            ]);

            if (Gate::allows('admin')) {
                $permissions['control_panel_admin'] = $this->getControlPanelAccess([
                    'id' => 'control_panel_admin',
                    'name' => 'Control Panel Admin',
                ]);
            }
        }
        $app_instances = Organization::apps()->filter(function ($item) {
            return ! in_array($item->status, ['deactivating', 'activating', 'deleting', 'deactivated']);
        });

        foreach ($app_instances as $app_instance) {
            if ($app_access = $this->getAppAccess($app_instance)) {
                $permissions[$app_instance->id] = $app_access;
            }
        }

        return $permissions;
    }

    public function getControlPanelAccess(array $access)
    {
        $has_access_method = 'has'.Str::studly($access['id']).'Access';
        $get_categories = 'get'.Str::studly($access['id']).'Categories';
        $has_access = $this->$has_access_method();

        return array_merge($access, [
            'categories' => $this->$get_categories(),
            'access_type' => $this->user->userAccessType(),
            'edit' => true,
            'access_type' => $has_access ? 'standard' : 'none',
            'pricing' => Subscription::base()->payment_enabled,
            'access_types' => [
                [
                    'text' => 'No access',
                    'value' => 'none',
                    'access_type' => 'none',
                    'disabled' => false,
                ],
                [
                    'text' => 'Access',
                    'value' => 'standard',
                    'access_type' => 'standard',
                    'disabled' => false,
                ],
            ],
        ]);
    }

    public function getControlPanelCategories()
    {
        $organizations = collect([Organization::account()])->merge(Organization::account()->suborganizations);
        $has_access = $this->hasControlPanelAccess($this->user);

        return [
            [
                'id' => 0,
                'name' => 'Organization',
                'active_role' => $has_access && $this->user->organization->name ? $this->user->organization->name : 'none',
                'roles' => collect([[
                    'text' => 'No access',
                    'value' => 'none',
                    'access_type' => 'none',
                    'disabled' => false,
                ]])->merge($organizations->map(function ($organization) {
                    return [
                        'value' => $organization->id,
                        'text' => $organization->name,
                        'disabled' => false,
                        'access_type' => 'standard',
                    ];
                }))->all(),
            ],
        ];
    }

    public function getControlPanelAdminCategories()
    {
        return [
            [
                'id' => 0,
                'name' => 'Control Panel',
                'active_role' => $this->hasControlPanelAdminAccess($this->user) ? 'Allowed' : 'none',
                'roles' => [
                    [
                        'text' => 'No access',
                        'value' => 'none',
                        'access_type' => 'none',
                        'disabled' => false,
                    ],
                    [
                        'text' => 'Allowed',
                        'value' => 'control_panel_standard',
                        'access_type' => 'standard',
                        'disabled' => false,
                    ],
                ],
            ],
        ];
    }

    public function getAppAccess(AppInstance $app_instance): ?array
    {
        $categories = $this->getAppCategories($app_instance);
        if (count($categories) === 0) {
            return null;
        }

        $can_edit = false;
        foreach ($categories as $category) {
            foreach ($category['roles'] as $role) {
                if (! $role['disabled']) {
                    $can_edit = true;
                }
            }
        }

        return [
            'id' => $app_instance->id,
            'name' => $app_instance->label,
            'categories' => $categories,
            'access_type' => $this->user->appUserAccessType($app_instance),
            'edit' => $can_edit,
            'access_types' => $this->getAppAccessTypes($app_instance),
            'pricing' => Subscription::app_instance($app_instance)->payment_enabled,
        ];
    }

    public function getAppCategories(AppInstance $app_instance)
    {
        $categories = [];
        $category_id = 0;
        $version_categories = $app_instance->version->group_categories();

        if (count($app_instance->version->roles()) > 0 && count($version_categories) > 0) {
            foreach ($version_categories as $category) {
                $roles = [];

                $roles[] = [
                    'value' => 'none',
                    'text' => 'None',
                    'access_type' => 'none',
                    'disabled' => false,
                ];
                $active_role = 'None';
                foreach ($app_instance->version->groupsFromCategory($category->category) as $role) {
                    $can_select_role = true;
                    if ($role->required_features && count($role->required_features) > 0) {
                        foreach ($role->required_features as $feature) {
                            if ($feature && ! Application::instance($app_instance)->features()->isActive($feature)) {
                                $can_select_role = false;
                                break;
                            }
                        }
                    }

                    if ($can_select_role) {
                        if ($this->user->hasAppRole($app_instance, $role)) {
                            $active_role = $role->name;
                        }
                        $disabled = false;
                        if ((Subscription::base()->type === 'package' && (($role->accessType() == 'standard' && ! $can_update_app_standard_user) || ($role->accessType() == 'basic' && ! $can_update_app_basic_user)) && $role->accessType() != 'minimal')
                            || (($role->accessType(app: $app_instance) == 'standard' && ! $can_update_app_standard_user) || ($role->accessType(app: $app_instance) == 'basic' && ! $can_update_app_basic_user) && $role->accessType(app: $app_instance) != 'minimal')
                        ) {
                            $disabled = true;
                        }

                        $role_text = $disabled ? $role->name.' - '.__('organization.user.permissions.denied.max_users') : $role->name;

                        $roles[] = [
                            'value' => $role->slug,
                            'text' => $role_text,
                            'disabled' => $disabled,
                            'description' => $role->description,
                            'access_type' => Subscription::base()->type === 'package' ? $role->accessType() : $role->accessType(app: $app_instance), // TODO: Get disabled access_types per user
                        ];
                    }
                }

                if (count($roles) > 1) {
                    $categories[] = [
                        'id' => $category_id,
                        'name' => $category->category,
                        'active_role' => $active_role,
                        'roles' => $roles,
                    ];
                    $category_id++;
                }
            }
        }

        return $categories;
    }

    public function getAppAccessTypes(AppInstance $app_instance)
    {
        $can_update_app_standard_user = Subscription::base()->type === 'package' ? Gate::allows('update-standard-user', $this->user) : Gate::allows('update-app-standard-user', [$this->user, $app_instance]);
        $can_update_app_basic_user = Subscription::base()->type === 'package' ? Gate::allows('update-basic-user', $this->user) : Gate::allows('update-app-basic-user', [$this->user, $app_instance]);
        $access_types = Subscription::base()->type === 'package' ? Subscription::base()->availableAccessTypesForUser($this->user) : Subscription::app_instance($app_instance)->availableAccessTypesForUser($this->user);
        $access_types = collect($access_types)->map(function ($access_type) use ($can_update_app_standard_user, $can_update_app_basic_user) {
            if ($access_type['value'] === 'standard') {
                $access_type['disabled'] = ! $can_update_app_standard_user;
            } elseif ($access_type['value'] === 'basic') {
                $access_type['disabled'] = ! $can_update_app_basic_user;
            } else {
                $access_type['disabled'] = false;
            }

            if ($access_type['disabled']) {
                $access_type['text'] = $access_type['text'].' - Max users reached';
            }

            return $access_type;
        });

        return $access_types;
    }

    public function addChange($type, AppInstance $app_instance, AppRole $role)
    {
        $app_name = $app_instance->application->slug;
        $change = $type == 'add' ? 'added' : 'removed';

        if (in_array($role->id, $app_instance->version->roles['order'])) {
            $this->changes[$change][] = [
                'role' => [
                    'id' => $role->slug,
                    'label' => $role->label,
                    'description' => $role->description,
                    'role_group' => Str::slug($role->category),
                ],
                'application' => $app_instance->label,
            ];
        }
    }

    public function addedPermissions()
    {
        $permissions = [];
        if (array_key_exists('added', $this->changes)) {
            $removed_groups = $this->changedRoleGroups('removed');
            foreach ($this->changes['added'] as $added) {
                if (! in_array($added['role']['role_group'], $removed_groups)) {
                    $permissions[] = [
                        'role' => $added['role']['label'],
                        'application' => $added['application'],
                    ];
                }
            }
        }

        return $permissions;
    }

    public function modifiedPermissions()
    {
        $permissions = [];
        $removed_roles = [];
        if (array_key_exists('added', $this->changes) && array_key_exists('removed', $this->changes)) {
            $removed_roles = $this->changedRoleGroups('removed');

            foreach ($this->changes['added'] as $added) {
                if (in_array($added['role']['role_group'], $removed_roles)) {
                    $permissions[] = [
                        'role' => $added['role']['label'],
                        'application' => $added['application'],
                    ];
                }
            }
        }

        return $permissions;
    }

    public function removedPermissions()
    {
        $permissions = [];

        if (array_key_exists('removed', $this->changes)) {
            foreach ($this->changes['removed'] as $removed) {
                if (! in_array($removed['role']['role_group'], $this->changedRoleGroups('added'))) {
                    $permissions[] = [
                        'role' => $removed['role']['label'],
                        'application' => $removed['application'],
                    ];
                }
            }
        }

        return $permissions;
    }

    public function accessPermissions()
    {
        $permissions = [];

        if (array_key_exists('access', $this->changes)) {
            foreach ($this->changes['access'] as $accessed) {
                $permissions[] = [
                    'access' => $accessed['access'],
                    'label' => Arr::get($accessed, 'label', ''),
                    'application' => $accessed['application'],
                ];
            }
        }

        return $permissions;
    }

    public function changedRoleGroups($type)
    {
        $removed_roles = [];
        if (array_key_exists($type, $this->changes)) {
            foreach ($this->changes[$type] as $removed) {
                if ($removed['role']['role_group']) {
                    $removed_roles[] = $removed['role']['role_group'];
                }
            }
        }

        return $removed_roles;
    }

    public function hasChanges()
    {
        return array_key_exists('added', $this->changes)
        || array_key_exists('modified', $this->changes)
        || array_key_exists('removed', $this->changes)
        || array_key_exists('access', $this->changes);
    }

    public function hasControlPanelAccess()
    {
        return $this->user->is_allowed;
    }

    public function addControlPanelAccess(?User &$user = null, ?\App\Organization $organization = null, bool $verified = false)
    {
        $this->user->assignRole('organization_admin');
        $this->user->is_allowed = true;
        if ($organization) {
            $user = $this->user->get();
            $user->organization()->associate($organization);
            if ($verified) {
                $user->email_verified_at = now();
            }
        }
        $user->save();

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel', [
            'access' => true,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function removeControlPanelAccess()
    {
        $this->user->removeRole('organization_admin');
        $this->user->is_allowed = false;
        $this->user->save();

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel', [
            'access' => false,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function addBillingManagerAccess()
    {
        $this->user->assignRole('billing_manager');

        Arr::set($this->changes, 'access.control_panel', [
            'access' => true,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function removeBillingManagerAccess()
    {
        $this->user->assignRole('billing_manager');

        Arr::set($this->changes, 'access.control_panel', [
            'access' => false,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function hasControlPanelAdminAccess()
    {
        return $this->user->hasRole('control_panel_admin');
    }

    public function addControlPanelAdminAccess(?User &$user = null)
    {
        $this->user->assignRole('control_panel_admin');
        $user = $this->user->get();
        $user->email_verified_at = now();
        $user->save();

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => true,
            'application' => env('APP_NAME').' '.__('labels.admin'),
        ]);
    }

    public function removeControlPanelAdminAccess()
    {
        $this->user->removeRole('control_panel_admin');

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => false,
            'application' => env('APP_NAME').' '.__('labels.admin'),
        ]);
    }

    public function changes()
    {
        return [
            'added' => $this->addedPermissions(),
            'modified' => $this->modifiedPermissions(),
            'removed' => $this->removedPermissions(),
            'access' => $this->accessPermissions(),
        ];
    }

    public function processRequest($request)
    {
        $array = [];

        foreach ($request as $app => $permissions) {
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

    public function appIdByRole(string $role)
    {
        $role = AppRole::where('slug', $role)->first();

        return $role ? $role->application->slug : null;
    }
}
