<?php

use App\AppInstance;
use App\BackupSchedule;
use App\Console\Calls\RecurringBackups;
use App\Exceptions\NoBackupDriverException;
use App\Organization;
use App\OrgBackup;
use App\OrgServer;
use App\RecurringBackup;
use App\Server;
use App\Support\Facades\Backup;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->support = new TestSupports;
    $this->support->seed();
    $this->support->activateDemoApp();
    config(['toggle.flags.app-backups' => true]);

    $this->admin = User::find(1);
    $this->organization = Organization::find(1);
});

afterEach(function () {
    $this->support->cleanLdap();
});

/**
 * The seeded app, with a database server of $interface. 'app_database' is the one
 * interface there is a backup driver for.
 *
 * @return array{0: AppInstance, 1: Server, 2: OrgServer}
 */
function appOnDatabaseServer(Organization $organization, string $interface): array
{
    $server = Server::factory()->create(['type' => 'database', 'interface' => $interface]);
    $orgServer = OrgServer::forceCreate(['organization_id' => $organization->id, 'server_id' => $server->id]);

    $app = AppInstance::where('organization_id', $organization->id)->first();
    $app->database_server_id = $orgServer->id;
    $app->save();

    return [$app, $server, $orgServer];
}

function scheduleFields(array $extra = []): array
{
    $when = now()->addDay();

    return $extra + [
        'date' => $when->toDateString(),
        'time' => $when->toDateTimeString(),
        'date_time' => $when->toDateTimeString(),
        'keep_for' => 7,
    ];
}

function orgBackup($test, string $type, int $app)
{
    return $test->actingAs($test->admin)->post("/admin/organizations/{$test->organization->id}/backups", scheduleFields([
        'backup' => $app,
        'backup_type' => $type,
    ]));
}

it('says which backup types there are drivers for', function () {
    // Plugins can register more types, so only the built-in one is pinned down
    expect(Backup::supportedTypes())->toContain('database')
        ->and(Backup::supports('database'))->toBeTrue()
        ->and(Backup::supports('web'))->toBeFalse();
});

it('refuses to schedule a backup of a type there is no driver for', function () {
    [$app] = appOnDatabaseServer($this->organization, 'app_database');

    orgBackup($this, 'web', $app->id)
        ->assertRedirect("/admin/organizations/{$this->organization->id}/backups")
        ->assertSessionHas('error');

    expect(OrgBackup::count())->toBe(0)->and(BackupSchedule::count())->toBe(0);
});

it('still schedules a database backup', function () {
    [$app] = appOnDatabaseServer($this->organization, 'app_database');

    orgBackup($this, 'database', $app->id)->assertSessionHas('success');

    expect(OrgBackup::count())->toBe(1)
        ->and(OrgBackup::first()->type)->toBe('database')
        ->and(OrgBackup::first()->status)->toBe('scheduled');
});

it('refuses a database backup when the backup server has no driver, and leaves nothing behind', function () {
    [$app] = appOnDatabaseServer($this->organization, 'no_such_driver');

    orgBackup($this, 'database', $app->id)->assertSessionHas('error');

    expect(OrgBackup::count())->toBe(0)->and(BackupSchedule::count())->toBe(0);
});

it('fails when a backup is scheduled on a server without a driver', function () {
    [$app, , $orgServer] = appOnDatabaseServer($this->organization, 'no_such_driver');
    $schedule = BackupSchedule::create(['scheduled_at' => now()->addDay()]);

    expect(fn () => Backup::schedule(
        $schedule,
        scheduled_at: now()->addDay()->toDateTimeString(),
        type: 'database',
        delete_after: 7,
        org_server: $orgServer,
        organization: $this->organization,
        app_instance: $app,
    ))->toThrow(NoBackupDriverException::class);

    expect(OrgBackup::count())->toBe(0);
});

it('only offers the servers that can be backed up when scheduling', function () {
    $database = Server::factory()->create(['type' => 'database', 'interface' => 'app_database']);
    $web = Server::factory()->create(['type' => 'web']);

    $this->actingAs($this->admin)->get('/admin/server/backup_scheduler')->assertInertia(fn ($page) => $page
        ->where('servers', fn ($servers) => collect($servers)->pluck('id')->contains($database->id)
            && ! collect($servers)->pluck('id')->contains($web->id))
    );

    $this->actingAs($this->admin)->get('/admin/server/backup_scheduler/recurring')->assertInertia(fn ($page) => $page
        ->where('servers', fn ($servers) => collect($servers)->pluck('id')->contains($database->id)
            && ! collect($servers)->pluck('id')->contains($web->id))
    );
});

it('refuses a server-wide backup of a server type there is no driver for', function () {
    $web = Server::factory()->create(['type' => 'web']);

    $this->actingAs($this->admin)
        ->post('/admin/server/backup_scheduler', scheduleFields(['server' => $web->id]))
        ->assertRedirect('/admin/server/backup_scheduler')
        ->assertSessionHas('error');

    expect(BackupSchedule::count())->toBe(0);
});

it('does not report a server-wide backup that scheduled nothing as a success', function () {
    // A database server with no app on it
    $server = Server::factory()->create(['type' => 'database', 'interface' => 'app_database']);

    $this->actingAs($this->admin)
        ->post('/admin/server/backup_scheduler', scheduleFields(['server' => $server->id]))
        ->assertSessionHas('error');

    expect(BackupSchedule::count())->toBe(0);
});

it('schedules a server-wide backup of the apps on a database server', function () {
    [, $server] = appOnDatabaseServer($this->organization, 'app_database');

    $this->actingAs($this->admin)
        ->post('/admin/server/backup_scheduler', scheduleFields(['server' => $server->id]))
        ->assertSessionHas('success');

    expect(OrgBackup::count())->toBe(1)->and(BackupSchedule::count())->toBe(1);
});

it('refuses a recurring backup of a server type there is no driver for', function () {
    $web = Server::factory()->create(['type' => 'web']);

    $this->actingAs($this->admin)
        ->post('/admin/server/backup_scheduler/recurring', [
            'time' => now()->toDateTimeString(),
            'recurrence' => 'daily',
            'keep_for' => 7,
            'keep_interval' => 'days',
            'server' => $web->id,
        ])
        ->assertRedirect('/admin/server/backup_scheduler/recurring')
        ->assertSessionHas('error');

    expect(RecurringBackup::count())->toBe(0);
});

it('turns off a recurring backup there is no driver for, once', function () {
    $web = Server::factory()->create(['type' => 'web']);
    $recurring = RecurringBackup::create([
        'server_id' => $web->id,
        'delete_after' => 7,
        'delete_interval' => 'days',
        'recurrence' => 'daily',
        'status' => 'active',
        'time' => '00:00',
        'type' => 'web',
    ]);

    (new RecurringBackups)();

    expect($recurring->refresh()->status)->toBe('inactive')
        ->and(BackupSchedule::count())->toBe(0);
});

it('skips an app whose backup server has no driver without stopping the run', function () {
    [$app] = appOnDatabaseServer($this->organization, 'no_such_driver');
    $server = Server::factory()->create(['type' => 'database', 'interface' => 'app_database']);

    $recurring = RecurringBackup::create([
        'server_id' => $server->id,
        'organization_id' => $this->organization->id,
        'application_id' => $app->application_id,
        'delete_after' => 7,
        'delete_interval' => 'days',
        'recurrence' => 'daily',
        'status' => 'active',
        'time' => '00:00',
        'type' => 'database',
    ]);

    (new RecurringBackups)();

    // The run finished, it just had nothing it could back up
    expect($recurring->refresh()->last_scheduled_at)->not->toBeNull()
        ->and(BackupSchedule::count())->toBe(1)
        ->and(OrgBackup::count())->toBe(0);
});
