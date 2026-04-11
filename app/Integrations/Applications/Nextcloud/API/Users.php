<?php

namespace App\Integrations\Applications\Nextcloud\API;

use App\Integrations\Applications\Nextcloud\Nextcloud;
use App\Support\AccountManager\UserManager;

class Users extends Nextcloud
{
    private $user;

    private $path;

    public function find($user)
    {
        $this->path = $this->basePath().'/users/'.$user;
        $this->action = 'find';

        $this->ignoreErrorCode(404)->get($this->path);

        $this->user = $this->response_content();

        $this->action = '';

        return $this->user;
    }

    public function add(array $user)
    {
        $this->path = $this->basePath().'/users';

        $data = [
            'userid' => $user['username'],
            'password' => $user['password'],
            'displayName' => $user['first_name'].' '.$user['last_name'],
            'email' => $user['email'],
            'groups' => $user['groups'],
            'subadmin' => array_key_exists('subadmin', $user) ? $user['subadmin'] : [],
            'quota' => $user['quota'],
            'language' => '',
        ];

        $this->form()->post($this->path, $data);

        return $this->response_content();
    }

    public function update(UserManager $user, string $key, mixed $value)
    {
        $this->path = $this->basePath().'/users/'.$user->attribute('username');

        $data = [
            'key' => $key,
            'value' => $value,
        ];

        $this->form()->cookies()->put($this->path, $data);

        return $this->response_content();
    }

    public function groups()
    {
        if ($this->user) {
            $path = $this->path.'/groups';
            $this->form()->get($path);

            if ($body = $this->response_content()) {

                $body = $body->groups->element;

                return $body;
            }
        }

        return null;
    }

    public function addToGroup($group = '')
    {
        if ($this->user) {
            $path = $this->path.'/groups';
            $data = ['groupid' => $group];

            $this->action_description = __('messages.api.nextcloud.users.add_to_group', ['group' => $group]);
            $this->form()->post($path, $data);
        }

        return $this;
    }

    public function removeFromGroup($group = '')
    {
        if ($this->user) {
            $this->request_type = 'DELETE';
            $path = $this->path.'/groups';
            $data = ['groupid' => $group];
            $this->action_description = __('messages.api.nextcloud.users.remove_from_group', ['group' => $group]);
            $this->form()->delete($path, $data);
        }

        return $this;
    }

    public function checkPermission($permission)
    {
        if ($this->user) {
            $groups = (array) $this->groups();

            if (! $this->error() && $groups) {
                $groups = isset($groups) ? $groups : [];

                if ($groups && in_array($permission, $groups)) {
                    return true;
                }
            }
        }

        return false;
    }
}
