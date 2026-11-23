<?php

use App\Actions\Apps\ApplicationSsoMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\AppInstance;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The admin-facing side of moving an app's sign-in to another SSO server: which servers the app
 * page offers, and the route that starts the move. The sequencing and rollback of the move
 * itself is covered by ApplicationSsoMoveTest.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();

    $this->admin = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = AppInstance::where('organization_id', $this->organization->id)->first();
    $this->old = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => moveSsoServer('old-sso')->id]);
    $this->appInstance->status = 'active';
    $this->appInstance->sso_server_id = $this->old->id;
    $this->appInstance->save();
});

function moveSsoServer(string $name, array $attributes = []): Server
{
    return Server::factory()->create($attributes + [
        'name' => $name,
        'type' => 'sso',
        'interface' => 'authentik',
        'host' => "{$name}.example.com",
        'default_web_server' => 0,
    ]);
}

function moveSsoUrl($test): string
{
    return "/admin/organizations/{$test->organization->id}/apps/{$test->appInstance->id}";
}

it('offers the other sign-in servers on the edit page', function () {
    $new = moveSsoServer('new-sso');
    moveSsoServer('inactive-sso', ['status' => 'inactive']);
    moveSsoServer('backup-sso', ['is_backup_server' => true]);

    $this->actingAs($this->admin)
        ->get(moveSsoUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Organizations/Apps/AppEdit')
            // Not the one it is on, one that is turned off, or one that only holds backups
            ->has('sso_servers', 1)
            ->where('sso_servers.0.value', $new->id)
            ->where('sso_servers.0.text', 'new-sso')
        );
});

it('offers no sign-in servers while the app is not active', function () {
    moveSsoServer('new-sso');
    $this->appInstance->status = 'updating';
    $this->appInstance->save();

    $this->actingAs($this->admin)
        ->get(moveSsoUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page->has('sso_servers', 0));
});

it('starts the move', function () {
    $new = moveSsoServer('new-sso');

    Action::shouldReceive('execute')
        ->once()
        ->with(Mockery::type(ApplicationSsoMove::class))
        ->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/move-sso', ['sso_server_id' => $new->id])
        ->assertRedirect(moveSsoUrl($this))
        ->assertSessionHas('success');
});

it('refuses the server the app is already on', function () {
    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/move-sso', ['sso_server_id' => $this->old->server_id])
        ->assertSessionHas('error', MoveBlockers::summary([__('messages.server_move.same_server', ['type' => 'sso'])]));
});

it('says everything that is in the way at once', function () {
    $new = moveSsoServer('new-sso', ['status' => 'inactive', 'is_backup_server' => true]);
    $this->appInstance->status = 'updating';
    $this->appInstance->save();

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/move-sso', ['sso_server_id' => $new->id])
        ->assertSessionHas('error', MoveBlockers::summary([
            __('messages.server_move.app_not_active', ['type' => 'sso']),
            __('messages.server_move.server_inactive'),
            __('messages.server_move.backup_server', ['type' => 'sso']),
        ]));
});

it('says so when a move to that server is already running', function () {
    $new = moveSsoServer('new-sso');

    Action::shouldReceive('execute')->once()->andReturn(null);

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/move-sso', ['sso_server_id' => $new->id])
        ->assertSessionHas('error', __('admin.organizations.apps.denied.sso_move_running'));
});

it('does not move an app through another organization', function () {
    $new = moveSsoServer('new-sso');
    $other = Organization::factory()->create();

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/move-sso", ['sso_server_id' => $new->id])
        ->assertNotFound();
});

it('needs a server that exists', function () {
    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/move-sso', ['sso_server_id' => 999999])
        ->assertSessionHasErrors('sso_server_id');
});
