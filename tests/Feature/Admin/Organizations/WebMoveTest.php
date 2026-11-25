<?php

use App\Actions\Apps\ApplicationWebCleanup;
use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\Actions\Apps\WebMove\AwaitVolume;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Integrations\Applications\Nextcloud\NextcloudProfile;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The admin-facing side of moving an app to another web server: which servers the app page
 * offers, the route that starts the move, and the form for the volume the move waits for. The
 * sequencing and rollback of the move itself is covered by ApplicationWebMoveTest.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

afterEach(function () {
    $this->restoreServerInterfaces();
    DemoAppProfile::$storage_handover = null;
});

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();

    (new TestSupports)->seed();

    $this->admin = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = $this->activeDemoApp();
    $this->old = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => moveWebServer('old-cluster')->id]);
    $this->appInstance->status = 'active';
    $this->appInstance->web_server_id = $this->old->id;
    $this->appInstance->save();
});

function moveWebServer(string $name, array $attributes = []): Server
{
    return Server::factory()->create($attributes + [
        'name' => $name,
        'type' => 'web',
        'interface' => 'rancher',
        'host' => "{$name}.example.com",
        'default_web_server' => 0,
    ]);
}

function moveWebUrl($test): string
{
    return "/admin/organizations/{$test->organization->id}/apps/{$test->appInstance->id}";
}

/**
 * The app's move, waiting for its volume to be restored on a cluster called "new-cluster".
 */
function moveWaitingForVolume($test): Task
{
    return Task::forceCreate([
        'organization_id' => $test->organization->id,
        'app_instance_id' => $test->appInstance->id,
        'action_slug' => 'application_web_move',
        'action_group' => 'system',
        'task_group' => 1,
        'description' => 'Moving',
        'status' => TaskStatus::IN_PROGRESS,
        'custom_values' => [
            'target_server_id' => moveWebServer('new-cluster')->id,
            'awaiting' => ['class' => AwaitVolume::class, 'message' => 'Restore the volume'],
        ],
    ]);
}



it('starts the move', function () {
    $new = moveWebServer('new-cluster');

    Action::shouldReceive('execute')
        ->once()
        ->with(Mockery::type(ApplicationWebMove::class))
        ->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id, 'storage' => ['volume' => 'new']])
        ->assertRedirect(moveWebUrl($this))
        ->assertSessionHas('success');
});

it('says everything that is in the way at once', function () {
    $other_kind = moveWebServer('direct-cluster', ['interface' => 'kubernetes']);
    $this->appInstance->status = 'updating';
    $this->appInstance->save();

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $other_kind->id, 'storage' => ['volume' => 'new']])
        ->assertSessionHas('error', MoveBlockers::summary([
            __('messages.server_move.app_not_active', ['type' => 'web']),
            __('messages.web_move.other_driver', ['server' => 'old-cluster', 'driver' => 'rancher']),
        ]));
});

it('refuses the server the app is already on', function () {
    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $this->old->server_id, 'storage' => ['volume' => 'new']])
        ->assertSessionHas('error', MoveBlockers::summary([__('messages.server_move.same_server', ['type' => 'web'])]));
});

it('says so when a move of the app is already running', function () {
    $new = moveWebServer('new-cluster');

    Action::shouldReceive('execute')->once()->andReturn(null);

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id, 'storage' => ['volume' => 'new']])
        ->assertSessionHas('error', __('admin.organizations.apps.denied.web_move_running'));
});

it('does not move an app through another organization, or to a server that is not there', function () {
    $new = moveWebServer('new-cluster');
    $other = Organization::factory()->create();

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/move-web", ['web_server_id' => $new->id, 'storage' => ['volume' => 'new']])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => 999999, 'storage' => ['volume' => 'new']])
        ->assertSessionHasErrors('web_server_id');
});





/**
 * A finished move of the app away from a cluster called "left-cluster", which left its release there.
 */
function finishedWebMove($test, array $values = [], ?OrgServer $previous = null): Task
{
    $previous ??= OrgServer::forceCreate(['organization_id' => $test->organization->id, 'server_id' => moveWebServer('left-cluster')->id]);

    return Task::forceCreate([
        'organization_id' => $test->organization->id,
        'app_instance_id' => $test->appInstance->id,
        'action_slug' => 'application_web_move',
        'action_group' => 'system',
        'task_group' => 1,
        'description' => 'Moving',
        'status' => TaskStatus::COMPLETE,
        'custom_values' => $values + ['previous_org_server_id' => $previous->id],
    ]);
}

it('lists the releases left on the servers the app ran on before', function () {
    $move = finishedWebMove($this);

    $this->actingAs($this->admin)
        ->get(moveWebUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page
            ->has('old_web_entries', 1)
            ->where('old_web_entries.0.id', $move->id)
            ->where('old_web_entries.0.server', 'left-cluster')
        );
});

it('does not list a release that was removed, or the one on the server the app is on', function () {
    finishedWebMove($this, ['old_release_removed_at' => now()->toDateTimeString()]);
    finishedWebMove($this, [], $this->old);

    $this->actingAs($this->admin)
        ->get(moveWebUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page->has('old_web_entries', 0));
});

it('starts removing an old release', function () {
    $move = finishedWebMove($this);

    Action::shouldReceive('execute')
        ->once()
        ->with(Mockery::type(ApplicationWebCleanup::class))
        ->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/remove-old-release', ['move_task_id' => $move->id])
        ->assertRedirect(moveWebUrl($this))
        ->assertSessionHas('success');
});

it('refuses to remove a release that is not left', function () {
    $removed = finishedWebMove($this, ['old_release_removed_at' => now()->toDateTimeString()]);

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/remove-old-release', ['move_task_id' => $removed->id])
        ->assertSessionHas('error', __('messages.web_move.nothing_to_remove'));
});

it('does not remove a release through another organization', function () {
    $move = finishedWebMove($this);
    $other = Organization::factory()->create();

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/remove-old-release", ['move_task_id' => $move->id])
        ->assertNotFound();
});

/**
 * The app on a database that only its own app server can reach, and a sign-in server, with another app
 * server to move to and a database server both can reach.
 *
 * @return array{Server, Server, Server}
 */
function moveWebWithServers($test, array $sso = []): array
{
    $new = moveWebServer('new-cluster');

    $inner = Server::factory()->create(['type' => 'database', 'name' => 'inner-db', 'host' => 'inner-db.example.com']);
    $inner->app_servers()->sync([$test->old->server_id]);
    $bridge = Server::factory()->create(['type' => 'database', 'name' => 'bridge-db', 'host' => 'bridge-db.example.com']);
    $bridge->app_servers()->sync([$test->old->server_id, $new->id]);
    $login = Server::factory()->create($sso + ['type' => 'sso', 'name' => 'login', 'host' => 'login.example.com']);

    $test->appInstance->database_server_id = OrgServer::forceCreate(['organization_id' => $test->organization->id, 'server_id' => $inner->id])->id;
    $test->appInstance->sso_server_id = OrgServer::forceCreate(['organization_id' => $test->organization->id, 'server_id' => $login->id])->id;
    $test->appInstance->save();

    return [$new, $inner, $bridge];
}




it('refuses to move the app to an app server that can\'t reach its database yet', function () {
    [$new] = moveWebWithServers($this);

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id, 'storage' => ['volume' => 'new']])
        ->assertSessionHas('error', MoveBlockers::summary([
            __('messages.server_move.cannot_reach_database', ['type' => 'web', 'server' => 'inner-db']),
        ]));
});

it('starts the move with the volume chosen', function () {
    DemoAppProfile::$storage_handover = (new NextcloudProfile)->storageHandover(new AppInstance);
    $new = moveWebServer('new-cluster');
    $storage = ['volume' => 'existing', 'claim' => 'restored-data', 'access_mode' => 'ReadWriteMany'];

    Action::shouldReceive('execute')
        ->once()
        ->with(Mockery::on(fn ($action) => $action instanceof ApplicationWebMove && $action->customValues()['storage'] === $storage))
        ->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id, 'storage' => $storage])
        ->assertSessionHas('success');
});

it('does not start the move with a volume that is not chosen properly', function () {
    DemoAppProfile::$storage_handover = (new NextcloudProfile)->storageHandover(new AppInstance);
    $new = moveWebServer('new-cluster');

    Action::shouldReceive('execute')->never();

    // An existing volume needs its claim; none at all needs to be said to be wanted
    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id, 'storage' => ['volume' => 'existing']])
        ->assertSessionHasErrors('storage.claim');

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id, 'storage' => ['claim' => 'restored-data']])
        ->assertSessionHasErrors('storage.volume');

    // A chart that can't be given one that exists
    DemoAppProfile::$storage_handover = null;

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id, 'storage' => ['volume' => 'existing', 'claim' => 'restored-data']])
        ->assertSessionHasErrors('storage.volume');
});

it('does not start the move without the volume being chosen', function () {
    $new = moveWebServer('new-cluster');

    Action::shouldReceive('execute')->never();

    $this->actingAs($this->admin)
        ->post(moveWebUrl($this).'/move-web', ['web_server_id' => $new->id])
        ->assertSessionHasErrors('storage');
});
