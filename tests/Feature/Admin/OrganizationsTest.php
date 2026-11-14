<?php

namespace Tests\Feature\Admin;

use App\Log;
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

    public function test_organization_logs_are_paginated_and_scoped_to_the_organization()
    {
        $admin = $this->adminUser();
        $organization = Organization::factory()->create(['type' => 'business']);
        $other = Organization::factory()->create(['type' => 'business']);

        $insert = fn ($orgId, $i) => [
            'organization_id' => $orgId,
            'message' => "log {$i}",
            'context' => '[]',
            'level' => '200',
            'level_name' => 'INFO',
            'channel' => 'test',
            'record_datetime' => now()->toDateTimeString(),
            'extra' => '[]',
            'formatted' => '',
            'created_at' => now()->subSeconds(100 - $i),
            'updated_at' => now(),
        ];
        Log::insert(array_map(fn ($i) => $insert($organization->id, $i), range(1, 45)));
        Log::insert([$insert($other->id, 99)]);

        $this->actingAs($admin)->get('/admin/organizations/'.$organization->id.'/logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Organizations/OrganizationLogs')
                ->has('logs', 30)
                ->where('meta.total', 45)
                ->where('meta.pages', 2)
                ->where('meta.page', 1));

        $this->actingAs($admin)->get('/admin/organizations/'.$organization->id.'/logs?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs', 15)
                ->where('meta.page', 2));
    }
}
