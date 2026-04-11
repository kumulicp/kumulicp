<?php

namespace App\Integrations\Applications\Wordpress\API;

use App\Integrations\Applications\Wordpress\Wordpress;
use Illuminate\Support\Arr;

class User extends Wordpress
{
    public function getUserID($username)
    {
        $done = false;
        $page = 1;

        while (! $done) {
            $path = $this->basePath().'/users';

            $this->get($path, [
                'page' => $page,
                'per_page' => 100,
                'context' => 'edit',
            ]);
            $users = $this->response_content();

            if ($users && count($users) > 0) {
                foreach ($users as $user) {
                    if ($user['username'] == $username) {
                        return $user['id'];
                    }
                }
            } else {
                $done = true;
            }

            $page++;
        }
    }

    public function updateUserRoles($user, $roles)
    {
        $roles = Arr::where($roles, function (string|int $value, int $key) {
            return $value != 'none';
        });

        $user_id = $this->getUserID($user);
        if ($user_id) {

            $format_roles = implode(',', $roles);

            $this->action_description = __('messages.api.wordpress.update_user_roles', ['roles' => $format_roles]);

            return $this->post($this->basePath().'/users/'.$user_id, [
                'roles' => $format_roles,
            ]);
        }

        $this->setError(__('organization.user.denied.exists'), 'update_user_role_failed', quiet: true);

        return null;
    }
}
