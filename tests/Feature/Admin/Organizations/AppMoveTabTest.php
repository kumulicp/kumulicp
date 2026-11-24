<?php

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
 * The tab for moving an app to another app server, in steps: what the page is given, worked out from
 * the servers, and where the actions it starts go back to.
 */
uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    DemoAppProfile::$sso = false;
});

beforeEach(function () {
    (new TestSupports)->seed();

    // The demo app signs people in through an SSO server
    DemoAppProfile::$sso = true;

    $this->admin = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = AppInstance::where('organization_id', $this->organization->id)->first();
    $this->appInstance->status = 'active';

    $this->oldCluster = tabServer('web', 'old-cluster', ['interface' => 'rancher']);
    $this->newCluster = tabServer('web', 'new-cluster', ['interface' => 'rancher']);

    // A database only the old app server can reach, one both can, and a sign-in server on the internet
    $this->innerDb = tabServer('database', 'inner-db');
    $this->innerDb->app_servers()->sync([$this->oldCluster->id]);
    $this->bridgeDb = tabServer('database', 'bridge-db');
    $this->bridgeDb->app_servers()->sync([$this->oldCluster->id, $this->newCluster->id]);
    $this->login = tabServer('sso', 'login');
    $this->otherLogin = tabServer('sso', 'other-login');

    $bind = fn (Server $server) => OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => $server->id])->id;
    $this->appInstance->web_server_id = $bind($this->oldCluster);
    $this->appInstance->database_server_id = $bind($this->innerDb);
    $this->appInstance->databasename = 'org_demo';
    $this->appInstance->sso_server_id = $bind($this->login);
    $this->appInstance->save();
});

function tabServer(string $type, string $name, array $attributes = []): Server
{
    // A database is one that can be backed up and restored to, which is what migrating to it needs
    $database = $type === 'database' ? ['interface' => 'app_database', 'internal_address' => "{$name}.db.svc"] : [];

    return Server::factory()->create($attributes + $database + [
        'type' => $type,
        'name' => $name,
        'host' => "{$name}.example.com",
        'default_web_server' => 0,
    ]);
}

function tabUrl($test, string $path = '/move'): string
{
    return "/admin/organizations/{$test->organization->id}/apps/{$test->appInstance->id}{$path}";
}

it('lists the app servers the app could be moved to, and takes the steps once one is chosen', function () {
    tabServer('web', 'direct-cluster', ['interface' => 'kubernetes']);
    tabServer('web', 'inactive-cluster', ['interface' => 'rancher', 'status' => 'inactive']);

    $this->actingAs($this->admin)
        ->get(tabUrl($this))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Organizations/Apps/AppMove')
            ->where('targets', function ($targets) {
                $names = collect($targets)->pluck('text');

                // Not the one it is on, another kind, or one that is turned off
                return $names->contains('new-cluster')
                    && ! $names->intersect(['old-cluster', 'direct-cluster', 'inactive-cluster'])->isNotEmpty();
            })
            ->where('target', null)
            ->where('steps', null)
        );
});

it('says what each step needs of the app server chosen', function () {
    $this->actingAs($this->admin)
        ->get(tabUrl($this, '/move?target='.$this->newCluster->id))
        ->assertInertia(fn ($page) => $page
            ->where('target.text', 'new-cluster')
            // The database is on a network of its own: it has to be moved to one both app servers can reach
            ->where('steps.database.server', 'inner-db')
            ->where('steps.database.reachable', false)
            ->where('steps.database.options', [['value' => $this->bridgeDb->id, 'text' => 'bridge-db']])
            // The sign-in server is on the internet, so it can be kept, or changed to another
            ->where('steps.sso.server', 'login')
            ->where('steps.sso.reachable', true)
            ->where('steps.sso.options', [['value' => $this->otherLogin->id, 'text' => 'other-login']])
            ->where('steps.app.ready', false)
            ->where('steps.app.storage.supports_existing', false)
        );
});

it('is ready for the app server once what the app uses can be reached from it', function () {
    $this->appInstance->database_server_id = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => $this->bridgeDb->id])->id;
    $this->appInstance->save();

    $this->actingAs($this->admin)
        ->get(tabUrl($this, '/move?target='.$this->newCluster->id))
        ->assertInertia(fn ($page) => $page
            ->where('steps.database.reachable', true)
            ->where('steps.app.ready', true)
        );
});

it('needs the sign-in changed when the new app server can\'t reach it', function () {
    $this->login->app_servers()->sync([$this->oldCluster->id]);
    $this->appInstance->database_server_id = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => $this->bridgeDb->id])->id;
    $this->appInstance->save();

    $this->actingAs($this->admin)
        ->get(tabUrl($this, '/move?target='.$this->newCluster->id))
        ->assertInertia(fn ($page) => $page
            ->where('steps.sso.reachable', false)
            ->where('steps.app.ready', false)
        );
});

it('ignores an app server that it could not be moved to', function () {
    $this->actingAs($this->admin)
        ->get(tabUrl($this, '/move?target=999999'))
        ->assertInertia(fn ($page) => $page->where('target', null)->where('steps', null));

    // Nor the one it is on
    $this->actingAs($this->admin)
        ->get(tabUrl($this, '/move?target='.$this->oldCluster->id))
        ->assertInertia(fn ($page) => $page->where('target', null));
});

it('says which moves of the app are being done', function () {
    $migration = Task::forceCreate([
        'organization_id' => $this->organization->id,
        'app_instance_id' => $this->appInstance->id,
        'action_slug' => 'application_database_migrate',
        'action_group' => 'system',
        'task_group' => 1,
        'description' => 'Migrating the database',
        'status' => TaskStatus::IN_PROGRESS,
    ]);
    Task::forceCreate([
        'organization_id' => $this->organization->id,
        'app_instance_id' => $this->appInstance->id,
        'action_slug' => 'application_sso_move',
        'action_group' => 'system',
        'task_group' => 2,
        'description' => 'Moving sign-in',
        'status' => TaskStatus::COMPLETE,
    ]);

    $this->actingAs($this->admin)
        ->get(tabUrl($this))
        ->assertInertia(fn ($page) => $page
            ->where('running.database.task_id', $migration->id)
            ->where('running.sso', null)
            ->where('running.web', null)
        );
});

it('does not show another organization\'s app', function () {
    $other = Organization::factory()->create();

    $this->actingAs($this->admin)
        ->get("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/move")
        ->assertNotFound();
});

it('goes back to the tab, with the app server being moved to, after a step that was started from it', function () {
    Action::shouldReceive('execute')->once()->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(tabUrl($this, '/move-sso'), ['sso_server_id' => $this->otherLogin->id, 'move_target' => $this->newCluster->id])
        ->assertRedirect(tabUrl($this, '/move?target='.$this->newCluster->id))
        ->assertSessionHas('success');
});

it('goes back to the tab with what was in the way, too', function () {
    Action::shouldReceive('execute')->never();

    // The server it is on already
    $this->actingAs($this->admin)
        ->post(tabUrl($this, '/move-sso'), ['sso_server_id' => $this->login->id, 'move_target' => $this->newCluster->id])
        ->assertRedirect(tabUrl($this, '/move?target='.$this->newCluster->id))
        ->assertSessionHas('error');
});

it('goes to the app\'s page when a step was not started from the tab', function () {
    Action::shouldReceive('execute')->once()->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(tabUrl($this, '/move-sso'), ['sso_server_id' => $this->otherLogin->id])
        ->assertRedirect(tabUrl($this, ''));
});

it('starts the move from the tab, and goes back to it', function () {
    // The database is on one both app servers can reach
    $this->appInstance->database_server_id = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => $this->bridgeDb->id])->id;
    $this->appInstance->save();

    Action::shouldReceive('execute')->once()->andReturn(new Task);

    $this->actingAs($this->admin)
        ->post(tabUrl($this, '/move-web'), [
            'web_server_id' => $this->newCluster->id,
            'storage' => ['volume' => 'new'],
            'move_target' => $this->newCluster->id,
        ])
        ->assertRedirect(tabUrl($this, '/move?target='.$this->newCluster->id))
        ->assertSessionHas('success');
});
