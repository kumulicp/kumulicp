<?php

use App\AppInstance;
use App\BackupSchedule;
use App\Organization;
use App\OrgBackup;
use App\OrgServer;
use App\Server;
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
    $this->demoApp = AppInstance::where('organization_id', $this->organization->id)->first();
    $this->base = "/admin/organizations/{$this->organization->id}/apps/{$this->demoApp->id}/backups";
});

afterEach(function () {
    $this->support->cleanLdap();
});

/** Gives the app a database server of $interface, and returns its OrgServer. */
function giveAppDatabase(AppInstance $app, string $interface): OrgServer
{
    $server = Server::factory()->create(['type' => 'database', 'interface' => $interface]);
    $orgServer = OrgServer::forceCreate(['organization_id' => $app->organization_id, 'server_id' => $server->id]);
    $app->database_server_id = $orgServer->id;
    $app->save();

    return $orgServer;
}

/** Another app in the same organization, of the same application, version and plan. */
function anotherApp(AppInstance $app): AppInstance
{
    return AppInstance::factory()->create([
        'organization_id' => $app->organization_id,
        'application_id' => $app->application_id,
        'version_id' => $app->version_id,
        'plan_id' => $app->plan_id,
    ]);
}

function completedBackup(AppInstance $app, OrgServer $orgServer, array $extra = []): OrgBackup
{
    return OrgBackup::forceCreate($extra + [
        'organization_id' => $app->organization_id,
        'app_instance_id' => $app->id,
        'org_server_id' => $orgServer->id,
        'scheduled_backup_id' => BackupSchedule::create(['scheduled_at' => now()->subDay()])->id,
        'action' => 'backup',
        'type' => 'database',
        'status' => 'completed',
        'backup_name' => 'backup-1',
        'scheduled_at' => now()->subDay(),
        'completed_at' => now()->subDay(),
    ]);
}

it('is not there when the app backups flag is off', function () {
    config(['toggle.flags.app-backups' => false]);

    $this->actingAs($this->admin)->get($this->base)->assertNotFound();
    $this->actingAs($this->admin)->post($this->base)->assertNotFound();
});

it('lists only the backups of the app', function () {
    $orgServer = giveAppDatabase($this->demoApp, 'app_database');
    $mine = completedBackup($this->demoApp, $orgServer);

    $other = anotherApp($this->demoApp);
    $theirs = completedBackup($other, $orgServer, ['backup_name' => 'other-app']);

    $this->actingAs($this->admin)->get($this->base)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Organizations/Apps/AppBackups')
        ->where('can_backup', true)
        ->where('backups', fn ($backups) => collect($backups)->pluck('id')->all() === [$mine->id])
    );
});

it('cannot back up an app whose database server has no backup driver', function () {
    giveAppDatabase($this->demoApp, 'no_such_driver');

    $this->actingAs($this->admin)->get($this->base)->assertInertia(fn ($page) => $page->where('can_backup', false));

    $this->actingAs($this->admin)->post($this->base)->assertRedirect($this->base)->assertSessionHas('error');

    expect(OrgBackup::count())->toBe(0)->and(BackupSchedule::count())->toBe(0);
});

it('backs the app up straight away, to be picked up by the backup runner', function () {
    giveAppDatabase($this->demoApp, 'app_database');

    $this->actingAs($this->admin)->post($this->base, ['keep_for' => 14])
        ->assertRedirect($this->base)
        ->assertSessionHas('success');

    $backup = OrgBackup::sole();
    expect($backup->action)->toBe('backup')
        ->and($backup->type)->toBe('database')
        ->and($backup->status)->toBe('scheduled')
        ->and($backup->app_instance_id)->toBe($this->demoApp->id)
        ->and($backup->organization_id)->toBe($this->organization->id)
        ->and(now()->parse($backup->scheduled_at)->lte(now()))->toBeTrue()
        ->and(now()->parse($backup->delete_at)->between(now()->addDays(13), now()->addDays(15)))->toBeTrue();
});

it('keeps a backup run from here for a month unless asked otherwise', function () {
    giveAppDatabase($this->demoApp, 'app_database');

    $this->actingAs($this->admin)->post($this->base)->assertSessionHas('success');

    expect(now()->parse(OrgBackup::sole()->delete_at)->between(now()->addDays(29), now()->addDays(31)))->toBeTrue();
});

it('restores a completed backup of the app to where it came from', function () {
    $orgServer = giveAppDatabase($this->demoApp, 'app_database');
    $backup = completedBackup($this->demoApp, $orgServer);

    $this->actingAs($this->admin)->post("{$this->base}/{$backup->id}/restore")
        ->assertRedirect($this->base)
        ->assertSessionHas('success');

    $restore = OrgBackup::where('action', 'restore')->sole();
    expect($restore->status)->toBe('scheduled')
        ->and($restore->backup_name)->toBe('backup-1')
        ->and($restore->app_instance_id)->toBe($this->demoApp->id)
        ->and($restore->org_server_id)->toBe($orgServer->id)
        ->and($restore->settings)->toBeNull();
});

it('refuses to restore a backup that has not completed', function () {
    $orgServer = giveAppDatabase($this->demoApp, 'app_database');
    $backup = completedBackup($this->demoApp, $orgServer, ['status' => 'failed']);

    $this->actingAs($this->admin)->post("{$this->base}/{$backup->id}/restore")->assertSessionHas('error');

    expect(OrgBackup::where('action', 'restore')->count())->toBe(0);
});

it('refuses to restore to a server that cannot take the backup', function () {
    $orgServer = giveAppDatabase($this->demoApp, 'app_database');
    $backup = completedBackup($this->demoApp, $orgServer);
    $web = Server::factory()->create(['type' => 'web']);

    $this->actingAs($this->admin)->post("{$this->base}/{$backup->id}/restore", ['database_server_id' => $web->id])
        ->assertSessionHas('error');

    expect(OrgBackup::where('action', 'restore')->count())->toBe(0);
});

it('does not restore a backup of another app through this one', function () {
    $orgServer = giveAppDatabase($this->demoApp, 'app_database');
    $other = anotherApp($this->demoApp);
    $theirs = completedBackup($other, $orgServer);

    $this->actingAs($this->admin)->post("{$this->base}/{$theirs->id}/restore")->assertNotFound();

    expect(OrgBackup::where('action', 'restore')->count())->toBe(0);
});

it('does not serve an app through another organization', function () {
    $other = Organization::factory()->create();

    $this->actingAs($this->admin)
        ->get("/admin/organizations/{$other->id}/apps/{$this->demoApp->id}/backups")
        ->assertNotFound();
});
