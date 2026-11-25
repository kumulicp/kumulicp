<?php

use App\Server;
use App\Support\ServerReach;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * Which app servers can reach a database or SSO server: said on the server, since nothing about a
 * server tells it. A database has to say; an SSO server with none is reachable from all.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();

    $this->admin = User::find(1);
    $this->appA = reachServer('web', 'app-a');
    $this->appB = reachServer('web', 'app-b');
});

function reachServer(string $type, string $name, array $attributes = []): Server
{
    // An interface its type has a profile for, which is what its edit page looks up
    $interface = ['web' => 'rancher', 'database' => 'app_database', 'sso' => 'authentik'][$type];

    return Server::factory()->create($attributes + ['type' => $type, 'interface' => $interface, 'name' => $name, 'host' => "{$name}.example.com"]);
}

function reachableBy(Server $server, Server ...$app_servers): Server
{
    $server->app_servers()->sync(collect($app_servers)->pluck('id'));

    return $server->fresh();
}

function updateServer($test, Server $server, array $data)
{
    return $test->actingAs($test->admin)->put("/admin/server/servers/{$server->id}", $data + [
        'name' => $server->name,
        'host' => $server->host,
        'address' => $server->address,
        'ip' => $server->ip,
        'internal_address' => $server->internal_address,
    ]);
}

it('says a database server can only be reached from the app servers it is assigned to', function () {
    $database = reachableBy(reachServer('database', 'db'), $this->appA);

    expect(ServerReach::reaches($this->appA, $database))->toBeTrue()
        ->and(ServerReach::reaches($this->appB, $database))->toBeFalse()
        // Not even by default: nothing says it is on any network
        ->and(ServerReach::reaches($this->appA, reachServer('database', 'unassigned')->fresh()))->toBeFalse();
});

it('says an SSO server is reachable from every app server unless it is assigned to some', function () {
    $open = reachServer('sso', 'open')->fresh();
    $restricted = reachableBy(reachServer('sso', 'restricted'), $this->appA);

    expect(ServerReach::reaches($this->appA, $open))->toBeTrue()
        ->and(ServerReach::reaches($this->appB, $open))->toBeTrue()
        ->and(ServerReach::reaches($this->appA, $restricted))->toBeTrue()
        ->and(ServerReach::reaches($this->appB, $restricted))->toBeFalse();
});

it('finds the servers every one of the app servers can reach', function () {
    reachableBy(reachServer('database', 'only-a'), $this->appA);
    reachableBy(reachServer('database', 'both'), $this->appA, $this->appB);
    reachServer('database', 'nobody');
    reachServer('database', 'inactive-both', ['status' => 'inactive'])->app_servers()->sync([$this->appA->id, $this->appB->id]);
    reachServer('database', 'backup-both', ['is_backup_server' => true])->app_servers()->sync([$this->appA->id, $this->appB->id]);

    expect(ServerReach::reachableFromAll('database', [$this->appA, $this->appB])->pluck('name')->all())->toBe(['both'])
        ->and(ServerReach::reachableFromAll('database', [$this->appA])->pluck('name')->all())->toBe(['both', 'only-a']);
});

it('gives the database servers that already exist all the app servers, and leaves SSO servers open', function () {
    $migration = require database_path('migrations/2026_11_23_000000_create_server_app_servers_table.php');
    $database = reachServer('database', 'db');
    $sso = reachServer('sso', 'sso');

    $migration->backfill();
    // Run again, as a migration run twice would: nothing is doubled
    $migration->backfill();

    $assigned = DB::table('server_app_servers')->where('server_id', $database->id)->pluck('app_server_id')->sort()->values()->all();
    expect($assigned)->toContain($this->appA->id, $this->appB->id)
        ->and(count($assigned))->toBe(count(array_unique($assigned)))
        ->and(DB::table('server_app_servers')->where('server_id', $sso->id)->count())->toBe(0);
});

it('shows the app servers a database server is assigned to, and the ones it can be assigned to', function () {
    $database = reachableBy(reachServer('database', 'db'), $this->appA);

    $this->actingAs($this->admin)
        ->get("/admin/server/servers/{$database->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('server.app_servers', [$this->appA->id])
            ->where('app_server_options', fn ($options) => collect($options)->pluck('id')->contains($this->appB->id))
        );
});

it('offers no app servers for a server that is not a database or SSO server', function () {
    $this->actingAs($this->admin)
        ->get("/admin/server/servers/{$this->appA->id}/edit")
        ->assertInertia(fn ($page) => $page->where('app_server_options', []));
});

it('assigns a database server to app servers, and needs at least one', function () {
    $database = reachServer('database', 'db');

    updateServer($this, $database, [])->assertSessionHasErrors('app_servers');
    updateServer($this, $database, ['app_servers' => []])->assertSessionHasErrors('app_servers');
    expect($database->app_servers()->count())->toBe(0);

    updateServer($this, $database, ['app_servers' => [$this->appA->id, $this->appB->id]])->assertSessionHasNoErrors();
    expect($database->fresh()->app_servers->pluck('id')->sort()->values()->all())->toBe([$this->appA->id, $this->appB->id]);

    // Narrowed down again
    updateServer($this, $database, ['app_servers' => [$this->appB->id]])->assertSessionHasNoErrors();
    expect($database->fresh()->app_servers->pluck('id')->all())->toBe([$this->appB->id]);
});

it('lets an SSO server be assigned to none, which is all of them', function () {
    $sso = reachableBy(reachServer('sso', 'sso'), $this->appA);

    updateServer($this, $sso, ['app_servers' => []])->assertSessionHasNoErrors();

    expect($sso->fresh()->app_servers)->toHaveCount(0)
        ->and(ServerReach::reaches($this->appB, $sso->fresh()))->toBeTrue();
});

it('only takes app servers to assign to', function () {
    $database = reachServer('database', 'db');
    $other_database = reachServer('database', 'other');

    updateServer($this, $database, ['app_servers' => [$other_database->id]])->assertSessionHasErrors('app_servers.0');
    updateServer($this, $database, ['app_servers' => [999999]])->assertSessionHasErrors('app_servers.0');
});

it('does not assign a web server to anything', function () {
    updateServer($this, $this->appA, ['app_servers' => [$this->appB->id]])->assertSessionHasNoErrors();

    expect($this->appA->fresh()->app_servers)->toHaveCount(0);
});
