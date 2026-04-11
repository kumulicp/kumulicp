<?php

namespace App\Integrations\Applications\Nextcloud\Services;

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Users;
use App\Support\ByteConversion;

class UserService
{
    public function __construct(private AppInstance $app_instance, private string $username) {}

    public function get()
    {
        $byte = new ByteConversion;

        $nextcloud_user = new Users($this->app_instance);
        $user = $nextcloud_user->find($this->username);

        return json_decode(json_encode([
            'username' => (string) $user->id,
            'quota' => [
                'free' => (int) $byte($user->quota->free, 'b', 'gb'),
                'used' => (int) $byte($user->quota->used, 'b', 'gb'),
                'total' => (int) $byte($user->quota->total, 'b', 'gb'),
            ],
            'email' => (string) $user->email,
            'display_name' => (string) $user->displayname,
        ]));
    }
}
