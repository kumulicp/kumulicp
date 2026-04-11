<?php

namespace Tests\Feature\Wordpress;

use App\AppInstance;
use App\Integrations\Applications\Wordpress\API\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Support\TestSupports;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_wordpress_user_api()
    {
        $support = new TestSupports;
        $support->seed();

        $wordpress = AppInstance::where('name', 'wordpress')->first();

        $user = new User($wordpress);
        $get = $user->getUserID('support');
        $update_roles = $user->updateUserRoles('support', ['administrator']);
        $this->assertTrue(in_array('administrator', Arr::get($update_roles, 'content.roles', [])));
    }
}
