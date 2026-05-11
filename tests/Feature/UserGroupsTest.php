<?php

namespace Tests\Feature;

use App\Group;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;
use Tests\TestCase;

class UserGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Queue::fake();
    }

    private function setup_env(): array
    {
        $support = new TestSupports;
        $support->seed();
        $support->addUsers();

        $admin = User::find(1);
        $this->actingAs($admin);

        $group_name = fake()->unique()->word;
        $this->post('/groups', [
            'name' => $group_name,
            'category' => 'others',
        ]);

        return ['admin' => $admin, 'group_slug' => $group_name];
    }

    public function test_user_groups_page_loads()
    {
        $this->withoutExceptionHandling();
        ['admin' => $admin] = $this->setup_env();

        $response = $this->get('/users/demo/groups');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Users/UserGroups')
            ->has('user')
            ->has('groups')
        );
    }

    public function test_user_groups_page_includes_user_current_groups()
    {
        $this->withoutExceptionHandling();
        ['group_slug' => $group_slug] = $this->setup_env();

        $this->get('/users/demo/groups/'.$group_slug.'/add');

        $response = $this->get('/users/demo/groups');

        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Users/UserGroups')
            ->where('user.groups', fn ($groups) => collect($groups)->pluck('slug')->contains($group_slug))
        );
    }

    public function test_add_user_to_group()
    {
        $this->withoutExceptionHandling();
        ['group_slug' => $group_slug] = $this->setup_env();

        $response = $this->get('/users/demo/groups/'.$group_slug.'/add');

        $response->assertRedirect('/users/demo/groups');

        $user = AccountManager::users()->find('demo');
        $user_group_slugs = $user->listGroups()->pluck('slug')->all();
        $this->assertContains($group_slug, $user_group_slugs);
    }

    public function test_remove_user_from_group()
    {
        $this->withoutExceptionHandling();
        ['group_slug' => $group_slug] = $this->setup_env();

        $this->get('/users/demo/groups/'.$group_slug.'/add');

        $response = $this->get('/users/demo/groups/'.$group_slug.'/remove');

        $response->assertRedirect('/users/demo/groups');

        $user = AccountManager::users()->find('demo');
        $user_group_slugs = $user->listGroups()->pluck('slug')->all();
        $this->assertNotContains($group_slug, $user_group_slugs);
    }

    public function test_add_and_remove_do_not_affect_other_group_memberships()
    {
        $this->withoutExceptionHandling();
        ['group_slug' => $group_slug] = $this->setup_env();

        $other_group_name = fake()->unique()->word;
        $this->post('/groups', [
            'name' => $other_group_name,
            'category' => 'others',
        ]);
        $this->get('/users/demo/groups/'.$other_group_name.'/add');

        $this->get('/users/demo/groups/'.$group_slug.'/add');
        $this->get('/users/demo/groups/'.$group_slug.'/remove');

        $user = AccountManager::users()->find('demo');
        $user_group_slugs = $user->listGroups()->pluck('slug')->all();
        $this->assertNotContains($group_slug, $user_group_slugs);
        $this->assertContains($other_group_name, $user_group_slugs);
    }

    public function test_user_groups_page_requires_authentication()
    {
        $support = new TestSupports;
        $support->seed();
        $support->addUsers();

        $response = $this->get('/users/demo/groups');

        $response->assertRedirect('/login');
    }
}
