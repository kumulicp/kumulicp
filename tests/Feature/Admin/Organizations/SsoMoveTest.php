<?php

use App\Actions\Apps\ApplicationSsoCleanup;
use App\Actions\Apps\ApplicationSsoMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The admin-facing side of moving an app's sign-in to another SSO server: which servers the app
 * page offers, and the route that starts the move. The sequencing and rollback of the move
 * itself is covered by ApplicationSsoMoveTest.
 */
uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    DemoAppProfile::$sso = false;
});

beforeEach(function () {
    (new TestSupports)->seed();

    // The demo app signs people in through an SSO server, unless a test says it does not
    DemoAppProfile::$sso = true;

    $this->admin = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = AppInstance::where('organization_id', $this->organization->id)->first();
    $this->old = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => moveSsoServer('old-sso')->id]);
    $this->appInstance->status = 'active';
    $this->appInstance->web_server_id = (OrgServer::where('organization_id', $this->organization->id)->where('server_id', 1)->first()
        ?? OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => 1]))->id;
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

it('says whether the app supports single sign-on, for the button to be turned off', function () {
    $this->actingAs($this->admin)
        ->get(moveSsoUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page->where('app.supports_sso', true));

    DemoAppProfile::$sso = false;

    $this->actingAs($this->admin)
        ->get(moveSsoUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page->where('app.supports_sso', false));
});

it('offers no sign-in servers to an app that does not support single sign-on', function () {
    moveSsoServer('new-sso');
    DemoAppProfile::$sso = false;

    $this->actingAs($this->admin)
        ->get(moveSsoUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page->has('sso_servers', 0));
});

it('refuses to move an app that does not support single sign-on', function () {
    $new = moveSsoServer('new-sso');
    DemoAppProfile::$sso = false;

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/move-sso', ['sso_server_id' => $new->id])
        ->assertSessionHas('error', MoveBlockers::summary([__('messages.server_move.no_sso_support', ['type' => 'sso'])]));
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

/**
 * A finished move of the app away from a server called "left-sso", which left its entry there.
 */
function finishedSsoMove($test, array $values = []): Task
{
    $left = OrgServer::forceCreate(['organization_id' => $test->organization->id, 'server_id' => moveSsoServer('left-sso')->id]);

    return Task::forceCreate([
        'organization_id' => $test->organization->id,
        'app_instance_id' => $test->appInstance->id,
        'action_slug' => 'application_sso_move',
        'action_group' => 'system',
        'task_group' => 1,
        'description' => 'Moving sign-in',
        'status' => TaskStatus::COMPLETE,
        'custom_values' => $values + ['previous_org_server_id' => $left->id, 'sso_snapshot' => ['sso' => ['pk' => 'left-app']]],
    ]);
}

it('lists the entries left on the servers the app signed in through before', function () {
    $move = finishedSsoMove($this);

    $this->actingAs($this->admin)
        ->get(moveSsoUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page
            ->has('old_sso_entries', 1)
            ->where('old_sso_entries.0.id', $move->id)
            ->where('old_sso_entries.0.server', 'left-sso')
        );
});

it('does not list an entry that was removed', function () {
    finishedSsoMove($this, ['old_sso_removed_at' => now()->toDateTimeString()]);

    $this->actingAs($this->admin)
        ->get(moveSsoUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page->has('old_sso_entries', 0));
});

it('starts removing an old entry', function () {
    $move = finishedSsoMove($this);

    Action::shouldReceive('execute')
        ->once()
        ->with(Mockery::type(ApplicationSsoCleanup::class))
        ->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/remove-old-sso', ['move_task_id' => $move->id])
        ->assertRedirect(moveSsoUrl($this))
        ->assertSessionHas('success');
});

it('refuses to remove an entry that is not left', function () {
    $removed = finishedSsoMove($this, ['old_sso_removed_at' => now()->toDateTimeString()]);

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveSsoUrl($this).'/remove-old-sso', ['move_task_id' => $removed->id])
        ->assertSessionHas('error', __('messages.sso_move.nothing_to_remove'));
});

it('does not remove an entry through another organization', function () {
    $move = finishedSsoMove($this);
    $other = Organization::factory()->create();

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/remove-old-sso", ['move_task_id' => $move->id])
        ->assertNotFound();
});
