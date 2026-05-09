<?php

namespace App\Integrations\Applications\CiviCRMStandalone\API;

use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandalone;

class User extends CiviCRMStandalone
{
    private ?array $user = null;

    public function find(string $username): static
    {
        $path = $this->basePath().'/ajax/api4/User/get';

        $this->json()->post($path, [
            'where' => [['name', '=', $username]],
            'select' => ['id', 'name', 'roles'],
        ]);

        $response = $this->response_content();

        if ($response && isset($response['values']) && count($response['values']) > 0) {
            $this->user = $response['values'][0];
        }

        return $this;
    }

    public function create(string $username): static
    {
        $path = $this->basePath().'/ajax/api4/User/create';

        $this->action_description = __('messages.api.civicrm.users.create_user', ['name' => $username]);

        $this->json()->post($path, [
            'values' => [
                'name' => $username,
            ],
        ]);

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

        $path = $this->basePath().'/ajax/api4/User/save';

        $this->action_description = __('messages.api.civicrm.users.update_roles', ['roles' => implode(', ', $roles) ?: 'none']);

        $this->json()->post($path, [
            'records' => [
                [
                    'id' => $this->user['id'],
                    'roles' => $roles,
                ],
            ],
        ]);

        return $this;
    }

    public function exists(): bool
    {
        return $this->user !== null;
    }
}
