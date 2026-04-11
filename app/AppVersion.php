<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AppVersion extends Model
{
    use HasFactory;

    protected $table = 'app_versions';

    protected $casts = [
        'roles' => 'array',
        'settings' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    public function roles(string|array|null $type = null, $all = true)
    {
        $list = [];
        $role_ids = $this->roles;

        if (isset($role_ids['order'])) {
            $roles = AppRole::where('access_type', '!=', 'disabled');
            $roles->where(function (Builder $query) use ($role_ids) {

                $n = 0;
                foreach ($role_ids['order'] as $id) {

                    if ($n == 0) {
                        $query->orWhere('id', $id);
                    } else {
                        $query->orWhere('id', $id);
                    }

                    $n++;
                }
            });

            if (is_string($type)) {
                $roles->where('access_type', $type);
            } elseif (is_array($type)) {
                $roles->where(function (Builder $query) use ($type) {
                    foreach ($type as $plan_type) {
                        $query->orWhere('access_type', $plan_type);
                    }
                });
            }

            if (! $all) {
                $roles->doesntHave('implied_roles');
            }
            $list = $roles->get();
        }

        return $list;
    }

    public function group_categories()
    {
        if (Arr::has($this->roles, 'order') && count($this->roles['order']) > 0) {
            $roles = AppRole::select('category');
            $roles->where('application_id', $this->application_id);
            $roles->where('access_type', '!=', 'disabled');

            $roles->where(function (Builder $query) {
                $version_roles = $this->roles;
                $n = 0;
                foreach ($version_roles['order'] as $version_role) {
                    if ($n == 0) {
                        $query->where('id', '=', $version_role);
                    } else {
                        $query->orWhere('id', '=', $version_role);
                    }

                    $n++;
                }
            });
            $version_roles = $this->roles;
            $order = implode(',', $version_roles['order']);
            $roles->orderByRaw('field(id,'.$order.')');
            $roles->groupBy('category');
            $categories = $roles->get();

            return $categories;
        }

        return collect();
    }

    public function groupsFromCategory($category)
    {
        $roles = AppRole::where('access_type', '!=', 'disabled');
        $roles->where('category', $category);

        $roles->where(function (Builder $query) {
            $version_roles = $this->roles;
            $n = 0;
            foreach ($version_roles['order'] as $version_role) {

                if ($n == 0) {
                    $query->where('id', '=', $version_role);
                } else {
                    $query->orWhere('id', '=', $version_role);
                }

                $n++;

            }
        });

        $version_roles = $this->roles;
        $order = implode(',', $version_roles['order']);
        $roles->orderByRaw('field(id,'.$order.')');

        return $roles->get();
    }

    public function defaultAdminRoles()
    {
        $roles = [];
        $role_ids = $this->roles;

        if (isset($role_ids['default_admin_groups'])) {

            foreach ($role_ids['default_admin_groups'] as $id) {

                $role = AppRole::where('id', $id)
                    ->where('access_type', '!=', 'disabled')
                    ->first();

                if ($role) {

                    $roles[] = $role;

                }
            }
        }

        return $roles;
    }

    public function defaultAdminRoleSlugs()
    {
        $roles = [];
        $role_ids = $this->roles;

        if (isset($role_ids['default_admin_roles'])) {

            foreach ($role_ids['default_admin_roles'] as $id) {

                $role = AppRole::where('id', $id)
                    ->where('access_type', '!=', 'disabled')
                    ->first();

                if ($role) {

                    $roles[] = $role->slug;

                }
            }
        }

        return $roles;
    }

    public function defaultUserRoles()
    {
        $roles = [];
        $role_ids = $this->roles;

        if (isset($role_ids['default_user_groups'])) {

            foreach ($role_ids['default_user_groups'] as $id) {

                $role = AppRole::where('id', $id)
                    ->where('access_type', '!=', 'disabled')
                    ->first();

                if ($role) {

                    $roles[] = $role;

                }
            }
        }

        return $roles;
    }

    public function hasRole($find_role)
    {
        $roles = $this->roles;
        if (is_array($roles) && array_key_exists('roles', $roles)) {
            foreach ($roles['roles'] as $role) {
                if (array_key_exists('subroles', $role)) {
                    foreach ($role['subroles'] as $subrole) {

                        if ($subrole['slug'] == $find_role) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function setting($key)
    {
        return ($this->settings && array_key_exists($key, $this->settings)) ? $this->settings[$key] : null;
    }

    public function updateSettings(array $new_settings)
    {
        $settings = $this->settings;

        foreach ($new_settings as $key => $value) {
            $settings[$key] = $value;
        }

        $this->settings = $settings;
        $this->save();
    }
}
