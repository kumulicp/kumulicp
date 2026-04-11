<?php

namespace Tests\Feature;

use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class GroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();

        // https://demo-nextcloud.example.com/apps/groupfolders/folders

        $this->withoutExceptionHandling();
        $user = User::find(1);
        $this->actingAs($user);

        $name = fake()->word;

        $new_group = $this->post('/groups', [
            'name' => $name,
            'category' => 'others',
        ]);

        $new_group->assertRedirectContains($name);

        $new_name = fake()->word;
        $edit = $this->put('/groups/'.$name, [
            'original_name' => $name,
            'name' => $new_name,
            'category' => 'others',
            'managers' => ['demo', 'testing1'],
            'members' => ['testing2'],
        ]);
        $edit->assertValid(['original_name', 'name', 'category', 'managers', 'members']);

        $group = AccountManager::accounts($user->organization)->groups()->find($new_name);
        $members = $group->members();
        $managers = $group->managerNames()->all();
        $this->assertTrue(in_array('demo', $members));
        $this->assertTrue(in_array('testing1', $members));
        $this->assertTrue(in_array('testing2', $members));
        $this->assertTrue(in_array('demo', $managers));
        $this->assertTrue(in_array('testing1', $managers));
        $this->assertFalse(in_array('testing2', $managers));

        $delete = $this->delete('/groups/'.$new_name);

        $this->assertNull(AccountManager::accounts($user->organization)->groups()->find($new_name));

    }
}
