<?php

use App\Actions\Apps\ServerMove\MoveBlockers;
use App\AppInstance;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The checks every move of an app's database, app (web) or SSO server makes before it
 * touches anything.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();

    $this->organization = Organization::find(1);
    $this->appInstance = AppInstance::where('organization_id', $this->organization->id)->first();
    $this->appInstance->status = 'active';
    $this->appInstance->save();
});

/**
 * A server of $type. The factory gives every server the same host, so each gets its own.
 */
function moveTarget(string $type, array $attributes = []): Server
{
    static $count = 0;
    $count++;

    return Server::factory()->create($attributes + ['type' => $type, 'name' => "{$type}-{$count}", 'host' => "{$type}-{$count}.example"]);
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

it('only knows the servers an app has', function () {
    MoveBlockers::target('email', moveTarget('web'));
})->throws(InvalidArgumentException::class);
