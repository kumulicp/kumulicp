<?php

use App\Actions\Apps\ServerMove\MoveBlockers;
use App\AppInstance;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The checks every move of an app's database, app (web) or SSO server makes before it
 * touches anything.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

afterEach(function () {
    $this->restoreServerInterfaces();
    DemoAppProfile::$sso = false;
});

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();

    (new TestSupports)->seed();

    // The demo app signs people in through an SSO server, unless a test says it does not
    DemoAppProfile::$sso = true;

    $this->organization = Organization::find(1);
    $this->appInstance = $this->activeDemoApp();
    $this->appInstance->status = 'active';
    // On an app server, as an app has to be for the others to be checked against
    $this->appInstance->web_server_id = (OrgServer::where('organization_id', $this->organization->id)->where('server_id', 1)->first()
        ?? OrgServer::forceCreate(['organization_id' => $this->organization->id, 'server_id' => 1]))->id;
    $this->appInstance->save();
});

/**
 * A server of $type. The factory gives every server the same host, so each gets its own.
 */
function moveTarget(string $type, array $attributes = []): Server
{
    static $count = 0;
    $count++;

    $server = Server::factory()->create($attributes + ['type' => $type, 'name' => "{$type}-{$count}", 'host' => "{$type}-{$count}.example"]);

    // A database is reached from the app servers it is assigned to: the app's own (the seeded one)
    if ($type === 'database') {
        $server->app_servers()->attach(1);
    }

    return $server;
}

/**
 * Puts the app on a server of $type, which is returned.
 */
function putAppOn(AppInstance $app, string $type, array $attributes = []): Server
{
    $server = moveTarget($type, $attributes);
    $binding = OrgServer::forceCreate(['organization_id' => $app->organization_id, 'server_id' => $server->id]);
    $app->{"{$type}_server_id"} = $binding->id;
    $app->save();

    return $server;
}

it('has nothing in the way of an active app moving to another active server', function (string $type) {
    putAppOn($this->appInstance, $type);

    expect(MoveBlockers::for($this->appInstance->fresh(), $type, moveTarget($type)))->toBeNull();
})->with(['database', 'web', 'sso']);

it('has nothing in the way of an app with no server of that type yet', function () {
    $this->appInstance->sso_server_id = null;
    $this->appInstance->save();

    expect(MoveBlockers::for($this->appInstance->fresh(), 'sso', moveTarget('sso')))->toBeNull();
});

it('refuses an app of another organization than the one asked for', function () {
    $other = Organization::factory()->create();

    expect(MoveBlockers::for($this->appInstance, 'web', moveTarget('web'), organization: $other))
        ->toBe(__('messages.server_move.wrong_organization', ['type' => 'web']))
        ->and(MoveBlockers::for($this->appInstance, 'web', moveTarget('web'), organization: $this->organization))
        ->toBeNull();
});

it('refuses an app that is not active, unless that is not asked', function () {
    $this->appInstance->status = 'updating';

    expect(MoveBlockers::for($this->appInstance, 'web', moveTarget('web')))
        ->toBe(__('messages.server_move.app_not_active', ['type' => 'web']))
        ->and(MoveBlockers::for($this->appInstance, 'web', moveTarget('web'), require_active: false))
        ->toBeNull();
});

it('refuses a server of another type, an inactive one and one that only holds backups', function () {
    expect(MoveBlockers::target('database', moveTarget('sso')))->toBe([MoveBlockers::WRONG_TYPE])
        ->and(MoveBlockers::target('database', moveTarget('database', ['status' => 'inactive'])))->toBe([MoveBlockers::SERVER_INACTIVE])
        ->and(MoveBlockers::target('database', moveTarget('database', ['is_backup_server' => true])))->toBe([MoveBlockers::BACKUP_SERVER])
        ->and(MoveBlockers::target('database', moveTarget('database')))->toBe([]);
});

it('refuses the server the app is already on', function (string $type) {
    $current = putAppOn($this->appInstance, $type);

    expect(MoveBlockers::for($this->appInstance->fresh(), $type, $current))
        ->toBe(__('messages.server_move.same_server', ['type' => $type]));
})->with(['database', 'web', 'sso']);

it('refuses another database server record for the same host, but not another web server on one', function () {
    $database = putAppOn($this->appInstance, 'database');
    $web = putAppOn($this->appInstance, 'web');
    $app = $this->appInstance->fresh();

    expect(MoveBlockers::same($app, 'database', moveTarget('database', ['host' => $database->host])))->toBe([MoveBlockers::SAME_SERVER])
        ->and(MoveBlockers::same($app, 'web', moveTarget('web', ['host' => $web->host])))->toBe([]);
});

it('refuses a web move for an app with child apps, but not the others', function () {
    putAppOn($this->appInstance, 'web');
    $child = AppInstance::factory()->create([
        'application_id' => $this->appInstance->application_id,
        'organization_id' => $this->appInstance->organization_id,
        'version_id' => $this->appInstance->version_id,
        'plan_id' => $this->appInstance->plan_id,
        'parent_id' => $this->appInstance->id,
        'status' => 'active',
    ]);
    $app = $this->appInstance->fresh();

    expect(MoveBlockers::app($app, 'web'))->toBe([MoveBlockers::HAS_CHILDREN])
        ->and(MoveBlockers::app($app, 'database'))->toBe([])
        ->and(MoveBlockers::app($app, 'sso'))->toBe([]);

    // One that is going away does not count
    $child->status = 'deactivated';
    $child->save();

    expect(MoveBlockers::app($app, 'web'))->toBe([]);
});

it('refuses a web or sso move for an app that runs as part of its parent', function () {
    $child = Mockery::mock(AppInstance::class)->makePartial();
    $child->status = 'active';
    $child->shouldReceive('children->notDeactivated->exists')->andReturn(false);
    $child->shouldReceive('usesOwnResources')->andReturn(false);
    $child->shouldReceive('supportsSso')->andReturn(true);

    expect(MoveBlockers::app($child, 'web'))->toBe([MoveBlockers::IS_CHILD])
        ->and(MoveBlockers::app($child, 'sso'))->toBe([MoveBlockers::IS_CHILD])
        ->and(MoveBlockers::app($child, 'database'))->toBe([]);
});

it('lists everything in the way at once, not one thing at a time', function () {
    $current = putAppOn($this->appInstance, 'database');
    $this->appInstance->status = 'updating';
    $other = Organization::factory()->create();
    // Wrong type, inactive and a backup server, all in one
    $target = moveTarget('sso', ['status' => 'inactive', 'is_backup_server' => true, 'host' => $current->host]);

    $all = MoveBlockers::all($this->appInstance, 'database', $target, organization: $other);

    expect($all)->toBe([
        __('messages.server_move.wrong_organization', ['type' => 'database']),
        __('messages.server_move.app_not_active', ['type' => 'database']),
        __('messages.server_move.wrong_type', ['type' => 'database']),
        __('messages.server_move.server_inactive', ['type' => 'database']),
        __('messages.server_move.backup_server', ['type' => 'database']),
        __('messages.server_move.same_server', ['type' => 'database']),
    ])->and(MoveBlockers::for($this->appInstance, 'database', $target, organization: $other))->toBe($all[0]);
});

it('joins messages into one text', function () {
    expect(MoveBlockers::summary(['One', 'Two']))->toBe('One. Two.');
});

it('refuses an sso move for an app that does not support single sign-on, but no other move', function () {
    DemoAppProfile::$sso = false;
    $app = $this->appInstance->fresh();

    expect(MoveBlockers::app($app, 'sso'))->toBe([MoveBlockers::NO_SSO_SUPPORT])
        ->and(MoveBlockers::app($app, 'database'))->toBe([])
        ->and(MoveBlockers::app($app, 'web'))->toBe([])
        ->and(MoveBlockers::for($app, 'sso', moveTarget('sso')))
        ->toBe(__('messages.server_move.no_sso_support', ['type' => 'sso']));
});

it('only knows the servers an app has', function () {
    MoveBlockers::target('email', moveTarget('web'));
})->throws(InvalidArgumentException::class);

it('only takes a database or SSO server the app\'s app server can reach', function () {
    $other_app_server = moveTarget('web');
    $far_database = moveTarget('database');
    $far_database->app_servers()->sync([$other_app_server->id]);
    $far_sso = moveTarget('sso');
    $far_sso->app_servers()->sync([$other_app_server->id]);
    $app = $this->appInstance->fresh();

    expect(MoveBlockers::reach($app, 'database', $far_database->fresh()))->toBe([MoveBlockers::NOT_REACHABLE])
        ->and(MoveBlockers::reach($app, 'sso', $far_sso->fresh()))->toBe([MoveBlockers::NOT_REACHABLE])
        // An SSO server that is assigned to none is on the internet
        ->and(MoveBlockers::reach($app, 'sso', moveTarget('sso')->fresh()))->toBe([])
        ->and(MoveBlockers::reach($app, 'database', moveTarget('database')->fresh()))->toBe([])
        ->and(MoveBlockers::for($app, 'database', $far_database->fresh()))
        ->toBe(__('messages.server_move.not_reachable', ['type' => 'database', 'app_server' => 'Rancher']));
});

it('needs the app to have an app server, to say whether it can reach a database or SSO server', function () {
    $this->appInstance->web_server_id = null;
    $this->appInstance->save();
    $app = $this->appInstance->fresh();

    expect(MoveBlockers::reach($app, 'database', moveTarget('database')->fresh()))->toBe([MoveBlockers::NO_APP_SERVER])
        ->and(MoveBlockers::reach($app, 'sso', moveTarget('sso')->fresh()))->toBe([MoveBlockers::NO_APP_SERVER])
        // The app server being moved to is the one that is chosen
        ->and(MoveBlockers::reach($app, 'web', moveTarget('web')))->toBe([]);
});

it('only moves the app to an app server that can reach its database and SSO server', function () {
    $database = putAppOn($this->appInstance, 'database');
    $sso = putAppOn($this->appInstance, 'sso');
    $sso->app_servers()->sync([1]);
    $new_app_server = moveTarget('web');
    $app = $this->appInstance->fresh();

    expect(MoveBlockers::reach($app, 'web', $new_app_server))->toBe([MoveBlockers::CANNOT_REACH_DATABASE, MoveBlockers::CANNOT_REACH_SSO])
        ->and(MoveBlockers::all($app, 'web', $new_app_server))->toContain(
            __('messages.server_move.cannot_reach_database', ['type' => 'web', 'server' => $database->name]),
            __('messages.server_move.cannot_reach_sso', ['type' => 'web', 'server' => $sso->name]),
        );

    // Once they can: the database is assigned to both app servers, and the SSO server is open
    $database->app_servers()->attach($new_app_server->id);
    $sso->app_servers()->sync([]);

    expect(MoveBlockers::reach($app->fresh(), 'web', $new_app_server->fresh()))->toBe([]);
});

it('has no reach to check for an app that uses no database or SSO server', function () {
    expect(MoveBlockers::reach($this->appInstance->fresh(), 'web', moveTarget('web')))->toBe([]);
});
