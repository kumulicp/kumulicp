<?php

use App\AppInstance;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

afterEach(function () {
    $this->restoreServerInterfaces();
});

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();

    (new TestSupports)->seed();

    $this->user = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = $this->activeDemoApp();
    $this->appInstance->status = 'active';
    $this->appInstance->save();
});

function updateAppServers($test, array $data)
{
    return $test->actingAs($test->user)->put(
        "/admin/organizations/{$test->organization->id}/apps/{$test->appInstance->id}",
        $data + ['settings' => '{}'],
    );
}

function bindingFor(Organization $organization, Server $server): ?OrgServer
{
    return OrgServer::where('organization_id', $organization->id)->where('server_id', $server->id)->first();
}

it('changes the database, app and sso servers of an app, binding the organization to each', function () {
    $database = Server::factory()->create(['type' => 'database']);
    $web = Server::factory()->create(['type' => 'web']);
    $sso = Server::factory()->create(['type' => 'sso']);

    expect(bindingFor($this->organization, $database))->toBeNull();

    updateAppServers($this, [
        'database_server_id' => $database->id,
        'web_server_id' => $web->id,
        'sso_server_id' => $sso->id,
    ])->assertRedirect("/admin/organizations/{$this->organization->id}/apps/{$this->appInstance->id}");

    $this->appInstance->refresh();
    expect($this->appInstance->database_server_id)->toBe(bindingFor($this->organization, $database)->id)
        ->and($this->appInstance->web_server_id)->toBe(bindingFor($this->organization, $web)->id)
        ->and($this->appInstance->sso_server_id)->toBe(bindingFor($this->organization, $sso)->id);
});

it('uses the binding the organization already has to a server', function () {
    $server = Server::factory()->create(['type' => 'database']);
    $existing = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => $server->id]);

    updateAppServers($this, ['database_server_id' => $server->id])->assertSessionHasNoErrors();

    expect($this->appInstance->refresh()->database_server_id)->toBe($existing->id)
        ->and(OrgServer::where('organization_id', $this->organization->id)->where('server_id', $server->id)->count())->toBe(1);
});

it('keeps the servers of an app when none are sent', function () {
    $before = $this->appInstance->only(['database_server_id', 'web_server_id', 'sso_server_id']);

    updateAppServers($this, [])->assertSessionHasNoErrors();

    expect($this->appInstance->refresh()->only(['database_server_id', 'web_server_id', 'sso_server_id']))->toBe($before);
});

it('keeps a server of an app that is sent empty', function () {
    $before = $this->appInstance->web_server_id;

    updateAppServers($this, ['web_server_id' => null])->assertSessionHasNoErrors();

    expect($this->appInstance->refresh()->web_server_id)->toBe($before);
});

it('does not accept a server of the wrong type', function () {
    $sso = Server::factory()->create(['type' => 'sso']);

    updateAppServers($this, ['database_server_id' => $sso->id])->assertSessionHasErrors('database_server_id');
});

it('does not accept an inactive server or a backup server', function () {
    $inactive = Server::factory()->create(['type' => 'database', 'status' => 'inactive']);
    $backup = Server::factory()->create(['type' => 'database', 'is_backup_server' => true]);

    updateAppServers($this, ['database_server_id' => $inactive->id])->assertSessionHasErrors('database_server_id');
    updateAppServers($this, ['database_server_id' => $backup->id])->assertSessionHasErrors('database_server_id');
});

it('does not change the servers of an app that is not active', function () {
    $database = Server::factory()->create(['type' => 'database']);
    $before = $this->appInstance->database_server_id;
    $this->appInstance->status = 'updating';
    $this->appInstance->save();

    updateAppServers($this, ['database_server_id' => $database->id])->assertSessionHas('error');

    expect($this->appInstance->refresh()->database_server_id)->toBe($before)
        ->and(bindingFor($this->organization, $database))->toBeNull();
});

it('still saves the settings of an app that is not active when its servers do not change', function () {
    $this->appInstance->status = 'updating';
    $this->appInstance->save();

    updateAppServers($this, ['settings' => '{"a":1}'])->assertSessionHasNoErrors();

    expect($this->appInstance->refresh()->settings)->toBe(['a' => 1]);
});
