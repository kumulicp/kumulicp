<?php

use App\Organization;
use App\OrgServer;
use App\Server;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();

    $this->user = User::find(1);
    $this->organization = Organization::find(1);
});

function bindOrgServer(Organization $organization, Server $server): OrgServer
{
    return OrgServer::forceCreate(['organization_id' => $organization->id, 'server_id' => $server->id]);
}

function setBackup($test, OrgServer $orgServer, ?Server $backup)
{
    return $test->actingAs($test->user)->put(
        "/admin/organizations/{$test->organization->id}/servers/{$orgServer->id}",
        ['backup_server_id' => $backup?->id],
    );
}

it('lists the servers of an organization and the backup servers it can use', function () {
    $database = bindOrgServer($this->organization, Server::factory()->create(['type' => 'database']));
    $backup = Server::factory()->create(['type' => 'database', 'is_backup_server' => true]);
    $ssoBackup = Server::factory()->create(['type' => 'sso', 'is_backup_server' => true]);

    $this->actingAs($this->user)
        ->get("/admin/organizations/{$this->organization->id}/servers")
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Organizations/Servers/ServersList')
            ->where('org_servers', fn ($servers) => collect($servers)->pluck('id')->contains($database->id))
            // The organization is not on it yet
            ->where('backup_servers', fn ($servers) => collect($servers)->pluck('id')->contains($backup->id))
            // Only for the types the organization has
            ->where('backup_servers', fn ($servers) => ! collect($servers)->pluck('id')->contains($ssoBackup->id))
        );
});

it('changes the backup server of a server, binding the organization to it', function () {
    $database = bindOrgServer($this->organization, Server::factory()->create(['type' => 'database']));
    $backup = Server::factory()->create(['type' => 'database', 'is_backup_server' => true]);

    setBackup($this, $database, $backup)
        ->assertRedirect("/admin/organizations/{$this->organization->id}/servers");

    $binding = OrgServer::where('organization_id', $this->organization->id)->where('server_id', $backup->id)->first();
    expect($binding)->not->toBeNull()
        ->and($database->refresh()->backup_server_id)->toBe($binding->id);
});

it('uses the binding the organization already has to the backup server', function () {
    $database = bindOrgServer($this->organization, Server::factory()->create(['type' => 'database']));
    $backup = Server::factory()->create(['type' => 'database', 'is_backup_server' => true]);
    $existing = bindOrgServer($this->organization, $backup);

    setBackup($this, $database, $backup)->assertSessionHasNoErrors();

    expect($database->refresh()->backup_server_id)->toBe($existing->id)
        ->and(OrgServer::where('organization_id', $this->organization->id)->where('server_id', $backup->id)->count())->toBe(1);
});

it('removes the backup server of a server', function () {
    $backup = Server::factory()->create(['type' => 'database', 'is_backup_server' => true]);
    $database = bindOrgServer($this->organization, Server::factory()->create(['type' => 'database']));
    $database->backup_server_id = bindOrgServer($this->organization, $backup)->id;
    $database->save();

    setBackup($this, $database, null)->assertSessionHasNoErrors();

    expect($database->refresh()->backup_server_id)->toBeNull();
});

it('does not take a server that is not a backup server as a backup server', function () {
    $database = bindOrgServer($this->organization, Server::factory()->create(['type' => 'database']));
    $notBackup = Server::factory()->create(['type' => 'database']);

    setBackup($this, $database, $notBackup)->assertSessionHasErrors('backup_server_id');

    expect($database->refresh()->backup_server_id)->toBeNull();
});

it('does not take a backup server of another type or an inactive one', function () {
    $database = bindOrgServer($this->organization, Server::factory()->create(['type' => 'database']));
    $ssoBackup = Server::factory()->create(['type' => 'sso', 'is_backup_server' => true]);
    $inactive = Server::factory()->create(['type' => 'database', 'is_backup_server' => true, 'status' => 'inactive']);

    setBackup($this, $database, $ssoBackup)->assertSessionHasErrors('backup_server_id');
    setBackup($this, $database, $inactive)->assertSessionHasErrors('backup_server_id');
});

it('does not change a server of another organization', function () {
    $other = Organization::factory()->create();
    $otherServer = bindOrgServer($other, Server::factory()->create(['type' => 'database']));
    $backup = Server::factory()->create(['type' => 'database', 'is_backup_server' => true]);

    setBackup($this, $otherServer, $backup)->assertNotFound();

    expect($otherServer->refresh()->backup_server_id)->toBeNull();
});
