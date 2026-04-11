<?php

namespace Tests\Feature\Nextcloud;

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Users;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestSupports;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_nextcloud_users_api()
    {
        $support = new TestSupports;
        $support->seed();
        $nextcloud = AppInstance::where('name', 'nextcloud')->first();

        $userid = Str::lower(fake()->name());
        $first_name = fake()->firstName();
        $last_name = fake()->lastName();
        $users = new Users($nextcloud);
        $add = $users->add([
            'username' => $userid,
            'password' => Str::random(40),
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => fake()->email(),
            'groups' => [],
            'subadmin' => [],
            'quota' => '1.5Gb',
            'language' => '',
        ]);
        $this->assertEquals($userid, (string) $add->id);
        $find = $users->find($userid);
        $this->assertEquals(1, (int) $find->enabled);
        $this->assertEquals(0, count($users->groups()));
        $add_grop = $users->addToGroup('admin');
        $this->assertEquals(1, count($users->groups()));
        $this->assertTrue($users->checkPermission('admin'));
        $remove_group = $users->removeFromGroup('admin');
        $this->assertFalse($users->checkPermission('admin'));
    }
}
