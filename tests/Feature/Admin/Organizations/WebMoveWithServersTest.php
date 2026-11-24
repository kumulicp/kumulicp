<?php

use App\Actions\Apps\ServerMove\MoveBlockers;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * Moving an app to another app server along with its database: what the Move tab offers (servers
 * only the new app server has to reach: the old one is not updated), and what the route that starts
 * the move accepts. The sequencing and rollback are covered by ApplicationWebMoveTest.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();

    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    $this->admin = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = $this->runActivate($plan, $this->organization, $app);

    $this->oldCluster = moveTabWebServer('old-cluster');
    $this->old = OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => $this->oldCluster->id]);
    $this->appInstance->web_server_id = $this->old->id;
    $this->appInstance->status = 'active';
    $this->appInstance->save();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function moveTabWebServer(string $name): Server
{
    return Server::factory()->create([
        'name' => $name,
        'type' => 'web',
        'interface' => 'rancher',
        'host' => "{$name}.example.com",
        'ip' => '10.0.0.9',
        'default_web_server' => 0,
    ]);
}

/**
 * @param  array<int, int>  $reachable_from  ids of the web servers that reach it
 */
function moveTabDatabaseServer(string $name, array $reachable_from): Server
{
    $server = Server::factory()->create([
        'name' => $name,
        'type' => 'database',
        'interface' => 'app_database',
        'host' => "{$name}.db.internal",
        'internal_address' => "{$name}.db.svc",
        'default_web_server' => 0,
    ]);
    $server->app_servers()->sync($reachable_from);

    return $server;
}

function moveTabUrl($test, string $path = ''): string
{
    return "/admin/organizations/{$test->organization->id}/apps/{$test->appInstance->id}{$path}";
}

/**
 * The app has its database on a server only the old app server reaches.
 */
function moveTabAppWithDatabase($test): Server
{
    $database = moveTabDatabaseServer('old-db', [$test->oldCluster->id]);
    $org_server = OrgServer::forceCreate(['organization_id' => $test->organization->id, 'server_id' => $database->id]);
    $org_server->backup_server_id = $org_server->id;
    $org_server->save();

    $test->appInstance->database_server_id = $org_server->id;
    $test->appInstance->databasename = 'org_demo';
    $test->appInstance->save();

    return $database;
}

it('offers the database servers the new app server can reach, whether or not the old one can', function () {
    $new = moveTabWebServer('new-cluster');
    moveTabAppWithDatabase($this);
    moveTabDatabaseServer('both-db', [$this->oldCluster->id, $new->id]);
    $only_new = moveTabDatabaseServer('new-db', [$new->id]);
    moveTabDatabaseServer('elsewhere-db', [$this->oldCluster->id]);

    $this->actingAs($this->admin)
        ->get(moveTabUrl($this, '/move').'?target='.$new->id)
        ->assertInertia(fn ($page) => $page->where('steps.database', function ($step) use ($only_new) {
            expect($step['server'])->toBe('old-db')
                ->and($step['reachable'])->toBeFalse()
                // The one only the new app server reaches is one of them: the old one is not updated to use it
                ->and(collect($step['options'])->pluck('text')->sort()->values()->all())->toBe(['both-db', 'new-db'])
                ->and(collect($step['options'])->pluck('value')->contains($only_new->id))->toBeTrue();

            return true;
        }));
});

it('starts the move with a database server only the new app server can reach', function () {
    $new = moveTabWebServer('new-cluster');
    moveTabAppWithDatabase($this);
    $new_db = moveTabDatabaseServer('new-db', [$new->id]);

    $this->actingAs($this->admin)
        ->post(moveTabUrl($this, '/move-web'), ['web_server_id' => $new->id, 'database_server_id' => $new_db->id, 'storage' => ['volume' => 'new']])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('error');

    $task = Task::where('action_slug', 'application_web_move')->sole();
    expect($task->getValue('target_server_id'))->toBe($new->id)
        ->and($task->getValue('database_target_server_id'))->toBe($new_db->id)
        ->and($task->getValue('sso_target_server_id'))->toBeNull();
});

it('does not start the move to an app server that can\'t reach the database the app would have', function () {
    $new = moveTabWebServer('new-cluster');
    moveTabAppWithDatabase($this);
    $elsewhere = moveTabDatabaseServer('elsewhere-db', [$this->oldCluster->id]);

    $this->actingAs($this->admin)
        ->post(moveTabUrl($this, '/move-web'), ['web_server_id' => $new->id, 'database_server_id' => $elsewhere->id])
        ->assertSessionHas('error', MoveBlockers::summary([
            __('messages.server_move.cannot_reach_database', ['type' => 'web', 'server' => 'elsewhere-db']),
        ]));

    expect(Task::where('action_slug', 'application_web_move')->count())->toBe(0);
});

it('does not start the move to an app server that can\'t reach the database the app has, without another one', function () {
    $new = moveTabWebServer('new-cluster');
    moveTabAppWithDatabase($this);

    $this->actingAs($this->admin)
        ->post(moveTabUrl($this, '/move-web'), ['web_server_id' => $new->id])
        ->assertSessionHas('error', MoveBlockers::summary([
            __('messages.server_move.cannot_reach_database', ['type' => 'web', 'server' => 'old-db']),
        ]));
});

it('starts the move without a database server for an app whose database the new app server can reach', function () {
    $new = moveTabWebServer('new-cluster');
    $database = moveTabAppWithDatabase($this);
    $database->app_servers()->sync([$this->oldCluster->id, $new->id]);

    $this->actingAs($this->admin)
        ->post(moveTabUrl($this, '/move-web'), ['web_server_id' => $new->id, 'storage' => ['volume' => 'new']])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('error');

    $task = Task::where('action_slug', 'application_web_move')->sole();
    expect($task->getValue('database_target_server_id'))->toBeNull();
});
