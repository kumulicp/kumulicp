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

function linkOrgServer(Organization $organization, string $type, bool $backup = false): OrgServer
{
    $server = Server::factory()->create([
        'type' => $type,
        'name' => ($backup ? 'Backup ' : '').ucfirst($type),
        'is_backup_server' => $backup,
    ]);

    $orgServer = new OrgServer;
    $orgServer->organization_id = $organization->id;
    $orgServer->server_id = $server->id;
    $orgServer->save();

    return $orgServer;
}

it('lists the servers of an organization and the backup servers it can use', function () {
    $database = linkOrgServer($this->organization, 'database');
    $backup = linkOrgServer($this->organization, 'database', backup: true);

    $this->actingAs($this->user)
        ->get("/admin/organizations/{$this->organization->id}/servers")
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Organizations/Servers/ServersList')
            ->where('org_servers', fn ($servers) => collect($servers)->pluck('id')->contains($database->id))
            ->has('backup_servers', 1)
            ->where('backup_servers.0.id', $backup->id)
            ->where('backup_servers.0.type', 'database')
        );
});

it('changes the backup server of a server', function () {
    $database = linkOrgServer($this->organization, 'database');
    $backup = linkOrgServer($this->organization, 'database', backup: true);

    $this->actingAs($this->user)
        ->put("/admin/organizations/{$this->organization->id}/servers/{$database->id}", ['backup_server_id' => $backup->id])
        ->assertRedirect("/admin/organizations/{$this->organization->id}/servers");

    expect($database->refresh()->backup_server_id)->toBe($backup->id);
});

it('removes the backup server of a server', function () {
    $backup = linkOrgServer($this->organization, 'database', backup: true);
    $database = linkOrgServer($this->organization, 'database');
    $database->backup_server_id = $backup->id;
    $database->save();

    $this->actingAs($this->user)
        ->put("/admin/organizations/{$this->organization->id}/servers/{$database->id}", ['backup_server_id' => null])
        ->assertSessionHasNoErrors();

    expect($database->refresh()->backup_server_id)->toBeNull();
});

it('does not take a server that is not a backup server as a backup server', function () {
    $database = linkOrgServer($this->organization, 'database');
    $notBackup = linkOrgServer($this->organization, 'database');

    $this->actingAs($this->user)
        ->put("/admin/organizations/{$this->organization->id}/servers/{$database->id}", ['backup_server_id' => $notBackup->id])
        ->assertSessionHasErrors('backup_server_id');

    expect($database->refresh()->backup_server_id)->toBeNull();
});

it('does not take a backup server of another type', function () {
    $database = linkOrgServer($this->organization, 'database');
    $ssoBackup = linkOrgServer($this->organization, 'sso', backup: true);

    $this->actingAs($this->user)
        ->put("/admin/organizations/{$this->organization->id}/servers/{$database->id}", ['backup_server_id' => $ssoBackup->id])
        ->assertSessionHasErrors('backup_server_id');
});

it('does not take a backup server of another organization', function () {
    $other = Organization::factory()->create();
    $database = linkOrgServer($this->organization, 'database');
    $otherBackup = linkOrgServer($other, 'database', backup: true);

    $this->actingAs($this->user)
        ->put("/admin/organizations/{$this->organization->id}/servers/{$database->id}", ['backup_server_id' => $otherBackup->id])
        ->assertSessionHasErrors('backup_server_id');
});

it('does not change a server of another organization', function () {
    $other = Organization::factory()->create();
    $otherServer = linkOrgServer($other, 'database');
    $backup = linkOrgServer($this->organization, 'database', backup: true);

    $this->actingAs($this->user)
        ->put("/admin/organizations/{$this->organization->id}/servers/{$otherServer->id}", ['backup_server_id' => $backup->id])
        ->assertNotFound();

    expect($otherServer->refresh()->backup_server_id)->toBeNull();
});
