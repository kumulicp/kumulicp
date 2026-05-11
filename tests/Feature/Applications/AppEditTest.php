<?php

use App\AppInstance;
use App\Organization;
use App\Server;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();
});

it('returns org_servers grouped by server type on the edit page', function () {
    $user = User::find(1);
    $organization = Organization::find(1);
    $app = AppInstance::where('organization_id', $organization->id)->first();

    $dbServer = Server::factory()->create(['type' => 'database', 'name' => 'DB Server']);
    $ssoServer = Server::factory()->create(['type' => 'sso', 'name' => 'SSO Server']);

    DB::table('org_servers')->insert(['organization_id' => $organization->id, 'server_id' => $dbServer->id]);
    DB::table('org_servers')->insert(['organization_id' => $organization->id, 'server_id' => $ssoServer->id]);

    $response = $this->actingAs($user)->get("/admin/organizations/{$organization->id}/apps/{$app->id}/edit");

    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Organizations/Apps/AppEdit')
        ->has('org_servers.web')
        ->has('org_servers.database', 1)
        ->has('org_servers.sso', 1)
        ->where('org_servers.database.0.name', 'DB Server')
        ->where('org_servers.sso.0.name', 'SSO Server')
    );
});

it('does not include servers from other organizations', function () {
    $user = User::find(1);
    $organization = Organization::find(1);
    $app = AppInstance::where('organization_id', $organization->id)->first();

    $orgServer = Server::factory()->create(['type' => 'database', 'name' => 'Org DB Server']);
    $otherServer = Server::factory()->create(['type' => 'database', 'name' => 'Other DB Server']);

    DB::table('org_servers')->insert(['organization_id' => $organization->id, 'server_id' => $orgServer->id]);
    // $otherServer is not linked to this organization

    $response = $this->actingAs($user)->get("/admin/organizations/{$organization->id}/apps/{$app->id}/edit");

    $response->assertInertia(fn ($page) => $page
        ->has('org_servers.database', 1)
        ->where('org_servers.database.0.name', 'Org DB Server')
    );
});
