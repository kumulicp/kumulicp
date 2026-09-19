<?php

namespace Tests\Feature\Admin;

use App\Organization;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class OrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $support = new TestSupports;
        $support->seed();

        return User::find(1);
    }

    public function test_shared_organization_is_excluded_from_the_admin_organizations_list()
    {
        $admin = $this->adminUser();
        $sharedOrg = Organization::factory()->create(['type' => 'shared', 'name' => 'Shared Apps']);
        $customerOrg = Organization::factory()->create(['type' => 'business', 'name' => 'A Real Customer']);

        $response = $this->actingAs($admin)->get('/admin/organizations');

        $response->assertInertia(fn ($page) => $page
            ->where('organizations', fn ($organizations) => collect($organizations)->pluck('id')->contains($customerOrg->id)
                && ! collect($organizations)->pluck('id')->contains($sharedOrg->id)
            )
        );
    }
}
