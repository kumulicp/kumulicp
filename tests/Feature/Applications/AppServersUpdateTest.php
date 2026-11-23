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

    $this->user = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = AppInstance::where('organization_id', $this->organization->id)->first();
    $this->appInstance->status = 'active';
    $this->appInstance->save();
});

/**
 * A server of $type, and the organization's binding to it.
 */
function linkAppServer(Organization $organization, string $type): int
{
    $server = Server::factory()->create(['type' => $type, 'name' => ucfirst($type).' server']);

    return DB::table('org_servers')->insertGetId([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
    ]);
}

function updateAppServers($test, array $data)
{
    return $test->actingAs($test->user)->put(
        "/admin/organizations/{$test->organization->id}/apps/{$test->appInstance->id}",
        $data + ['settings' => '{}'],
    );
}

it('changes the database, app and sso servers of an app', function () {
    $database = linkAppServer($this->organization, 'database');
    $web = linkAppServer($this->organization, 'web');
    $sso = linkAppServer($this->organization, 'sso');

    updateAppServers($this, [
        'database_server_id' => $database,
        'web_server_id' => $web,
        'sso_server_id' => $sso,
    ])->assertRedirect("/admin/organizations/{$this->organization->id}/apps/{$this->appInstance->id}");

    $this->appInstance->refresh();
    expect($this->appInstance->database_server_id)->toBe($database)
        ->and($this->appInstance->web_server_id)->toBe($web)
        ->and($this->appInstance->sso_server_id)->toBe($sso);
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

it('does not accept a server of another organization', function () {
    $other = Organization::factory()->create();
    $database = linkAppServer($other, 'database');
    $before = $this->appInstance->database_server_id;

    updateAppServers($this, ['database_server_id' => $database])->assertSessionHasErrors('database_server_id');

    expect($this->appInstance->refresh()->database_server_id)->toBe($before);
});

it('does not accept a server of the wrong type', function () {
    $sso = linkAppServer($this->organization, 'sso');

    updateAppServers($this, ['database_server_id' => $sso])->assertSessionHasErrors('database_server_id');
});

it('does not change the servers of an app that is not active', function () {
    $database = linkAppServer($this->organization, 'database');
    $before = $this->appInstance->database_server_id;
    $this->appInstance->status = 'updating';
    $this->appInstance->save();

    updateAppServers($this, ['database_server_id' => $database])->assertSessionHas('error');

    expect($this->appInstance->refresh()->database_server_id)->toBe($before);
});

it('still saves the settings of an app that is not active when its servers do not change', function () {
    $this->appInstance->status = 'updating';
    $this->appInstance->save();

    updateAppServers($this, ['settings' => '{"a":1}'])->assertSessionHasNoErrors();

    expect($this->appInstance->refresh()->settings)->toBe(['a' => 1]);
});
