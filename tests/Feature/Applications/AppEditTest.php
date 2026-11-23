<?php

use App\AppInstance;
use App\Organization;
use App\Server;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();
});

function editPage($test)
{
    $organization = Organization::find(1);
    $app = AppInstance::where('organization_id', $organization->id)->first();

    return $test->actingAs(User::find(1))->get("/admin/organizations/{$organization->id}/apps/{$app->id}/edit");
}

it('offers the active servers of each type on the edit page, including ones the organization is not on', function () {
    $database = Server::factory()->create(['type' => 'database', 'name' => 'DB Server']);
    $sso = Server::factory()->create(['type' => 'sso', 'name' => 'SSO Server']);

    editPage($this)->assertInertia(fn ($page) => $page
        ->component('Admin/Organizations/Apps/AppEdit')
        ->has('server_options.web')
        ->where('server_options.database', fn ($servers) => collect($servers)->pluck('id')->contains($database->id))
        ->where('server_options.sso', fn ($servers) => collect($servers)->pluck('id')->contains($sso->id))
        ->where('server_options.sso', fn ($servers) => collect($servers)->pluck('type')->unique()->all() === ['sso'])
    );
});

it('does not offer inactive servers or backup servers', function () {
    $inactive = Server::factory()->create(['type' => 'database', 'status' => 'inactive']);
    $backup = Server::factory()->create(['type' => 'database', 'is_backup_server' => true]);

    editPage($this)->assertInertia(fn ($page) => $page
        ->where('server_options.database', fn ($servers) => ! collect($servers)->pluck('id')->intersect([$inactive->id, $backup->id])->isNotEmpty())
    );
});
