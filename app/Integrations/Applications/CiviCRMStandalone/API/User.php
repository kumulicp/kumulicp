<?php

namespace App\Integrations\Applications\CiviCRMStandalone\API;

use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandalone;

class User extends CiviCRMStandalone
{
    private ?array $user = null;

    public function find(string $username): static
    {
        $path = $this->basePath().'/ajax/api4/User/get';

        $this->form()->post($path, $this->data([
            'where' => [['username', '=', $username]],
            'select' => ['id', 'name', 'username', 'roles:name'],
        ]));

        $response = $this->response_content();

        if ($response && isset($response['values']) && count($response['values']) > 0) {
            $this->user = $response['values'][0];
        }

        return $this;
    }

    public function create(string $username, array $roles): static
    {
        $path = $this->basePath().'/ajax/api4/User/create';

        $this->action_description = __('messages.api.civicrm.users.create_user', ['name' => $username]);

        $this->form()->post($path, $this->data([
            'values' => [
                'username' => $username,
                'is_active' => true,
                'roles:name' => $roles,
            ],
        ]));

        $response = $this->response_content();

        if ($response && isset($response['values']) && count($response['values']) > 0) {
            $this->user = $response['values'][0];
        }

        return $this;
    }

    public function updateRoles(array $roles): static
    {
        if (! $this->user) {
            return $this;
        }

        $path = $this->basePath().'/ajax/api4/User/update';

        $this->action_description = __('messages.api.civicrm.users.update_roles', ['roles' => implode(', ', $roles) ?: 'none']);

        $this->form()->post($path, $this->data([
            'values' => ['roles:name' => $roles],
            'where' => [['username', '=', $this->user['username']]],
        ]));

        return $this;
    }

    public function exists(): bool
    {
        return $this->user !== null;
    }

    private function data(array $data)
    {
        return ['params' => json_encode($data)];
    }
}
