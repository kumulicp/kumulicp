<?php

namespace Tests\Feature\Admin\Organizations;

use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Integrations\ServerManagers\AppDatabase\BackupInterface;
use App\Organization;
use App\OrgBackup;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionProperty;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The admin-facing side of moving an app's database to another database
 * server: restoring a backup into a different server, the app page's migrate
 * button, and which host BackupInterface talks to for each. The sequencing and
 * rollback of the migration itself is covered by ApplicationDatabaseMigrateTest.
 */
class DatabaseMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    private AppInstance $appInstance;

    private OrgServer $old;

    protected function setUp(): void
    {
        parent::setUp();

        $support = new TestSupports;
        $support->seed();
        $support->activateDemoApp();

        $this->admin = User::find(1);
        $this->organization = Organization::factory()->create(['type' => 'business']);

        $demoApp = Application::where('slug', 'demo_app')->first();
        $plan = AppPlan::factory()->create(['application_id' => $demoApp->id, 'hidden' => false]);

        $this->old = OrgServer::create([
            'organization_id' => $this->organization->id,
            'server_id' => $this->databaseServer('old-db')->id,
        ]);
        $this->old->backup_server_id = $this->old->id;
        $this->old->save();

        $this->appInstance = new AppInstance;
        $this->appInstance->application_id = $demoApp->id;
        $this->appInstance->organization_id = $this->organization->id;
        $this->appInstance->version_id = 1;
        $this->appInstance->plan_id = $plan->id;
        $this->appInstance->name = 'demo-app-instance';
        $this->appInstance->label = 'Demo App Instance';
        $this->appInstance->status = 'active';
        $this->appInstance->databasename = 'org_demo';
        $this->appInstance->database_server_id = $this->old->id;
        $this->appInstance->save();
    }

    private function databaseServer(string $name): Server
    {
        return Server::factory()->create([
            'name' => $name,
            'type' => 'database',
            'interface' => 'app_database',
            'host' => "{$name}.db.internal",
            'internal_address' => "{$name}.db.svc",
            'default_web_server' => 0,
        ]);
    }

    private function completedBackup(): OrgBackup
    {
        return OrgBackup::create([
            'organization_id' => $this->organization->id,
            'app_instance_id' => $this->appInstance->id,
            'org_server_id' => $this->old->id,
            'action' => 'backup',
            'type' => 'database',
            'status' => 'completed',
            'backup_name' => 'dump-file',
            'scheduled_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);
    }

    public function test_a_database_backup_can_be_restored_to_a_different_database_server()
    {
        $backup = $this->completedBackup();
        $target = $this->databaseServer('new-db');

        $this->actingAs($this->admin)
            ->post("/admin/organizations/{$this->organization->id}/backups/{$backup->id}/restore", ['database_server_id' => $target->id])
            ->assertRedirect("/admin/organizations/{$this->organization->id}/backups")
            ->assertSessionHas('success');

        $new = OrgServer::where('organization_id', $this->organization->id)->where('server_id', $target->id)->first();
        $this->assertNotNull($new);

        $restore = OrgBackup::where('action', 'restore')->sole();
        $this->assertSame('scheduled', $restore->status);
        $this->assertSame('dump-file', $restore->backup_name);
        $this->assertSame($this->appInstance->id, $restore->app_instance_id);
        $this->assertSame(['target_org_server_id' => $new->id], $restore->settings);

        // Restoring doesn't move the app; that's what migrating is for
        $this->assertSame($this->old->id, $this->appInstance->fresh()->database_server_id);
    }

    public function test_a_backup_restores_to_its_original_server_by_default()
    {
        $backup = $this->completedBackup();

        $this->actingAs($this->admin)
            ->get("/admin/organizations/{$this->organization->id}/backups/{$backup->id}/restore")
            ->assertRedirect("/admin/organizations/{$this->organization->id}/backups");

        $restore = OrgBackup::where('action', 'restore')->sole();
        $this->assertNull($restore->settings);
        $this->assertSame($this->old->id, $restore->org_server_id);
    }

    public function test_a_backup_cannot_be_restored_to_a_server_that_is_not_a_database_server()
    {
        $backup = $this->completedBackup();

        $this->actingAs($this->admin)
            ->post("/admin/organizations/{$this->organization->id}/backups/{$backup->id}/restore", ['database_server_id' => 1])
            ->assertSessionHas('error', __('admin.backups.denied.restore_server'));

        $this->assertSame(0, OrgBackup::where('action', 'restore')->count());
    }

    public function test_a_backup_cannot_be_restored_through_another_organization()
    {
        $backup = $this->completedBackup();
        $other = Organization::factory()->create(['type' => 'business']);

        $this->actingAs($this->admin)
            ->post("/admin/organizations/{$other->id}/backups/{$backup->id}/restore")
            ->assertNotFound();
    }

    public function test_the_app_edit_page_offers_the_other_database_servers()
    {
        $new = $this->databaseServer('new-db');

        $this->actingAs($this->admin)
            ->get("/admin/organizations/{$this->organization->id}/apps/{$this->appInstance->id}/edit")
            ->assertInertia(fn ($page) => $page
                ->where('app.database_server', 'old-db')
                ->has('database_servers', 1)
                ->where('database_servers.0.value', $new->id)
                ->where('database_servers.0.text', 'new-db')
            );
    }

    public function test_migrating_starts_the_migration_action()
    {
        $new = $this->databaseServer('new-db');

        Action::shouldReceive('execute')
            ->once()
            ->with(Mockery::type(ApplicationDatabaseMigrate::class))
            ->andReturn(new Task);

        $this->actingAs($this->admin)
            ->post("/admin/organizations/{$this->organization->id}/apps/{$this->appInstance->id}/migrate-database", ['database_server_id' => $new->id])
            ->assertRedirect("/admin/organizations/{$this->organization->id}/apps/{$this->appInstance->id}")
            ->assertSessionHas('success');
    }

    public function test_migrating_to_the_server_the_app_is_already_on_is_refused()
    {
        Action::shouldReceive('execute')->never();

        $this->actingAs($this->admin)
            ->post("/admin/organizations/{$this->organization->id}/apps/{$this->appInstance->id}/migrate-database", ['database_server_id' => $this->old->server_id])
            ->assertSessionHas('error', __('messages.database_migration.same_server'));
    }

    public function test_migrating_an_app_through_another_organization_is_refused()
    {
        $new = $this->databaseServer('new-db');
        $other = Organization::factory()->create(['type' => 'business']);

        Action::shouldReceive('execute')->never();

        $this->actingAs($this->admin)
            ->post("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/migrate-database", ['database_server_id' => $new->id])
            ->assertNotFound();
    }

    public function test_backup_interface_dumps_from_the_named_source_and_restores_into_the_named_target()
    {
        $new = OrgServer::create([
            'organization_id' => $this->organization->id,
            'server_id' => $this->databaseServer('new-db')->id,
        ]);

        $host = fn (OrgBackup $backup) => (new ReflectionProperty(BackupInterface::class, 'host'))
            ->getValue(new BackupInterface($backup));

        // Nothing named: the backup server, as before
        $plain = $this->completedBackup();
        $this->assertSame('old-db.db.internal', $host($plain));

        $dump = $this->completedBackup();
        $dump->settings = ['source_org_server_id' => $this->old->id];
        $this->assertSame('old-db.db.internal', $host($dump));

        $restore = $this->completedBackup();
        $restore->action = 'restore';
        $restore->settings = ['target_org_server_id' => $new->id];
        $this->assertSame('new-db.db.internal', $host($restore));

        // A target only applies to restores, a source only to dumps
        $restore->settings = ['source_org_server_id' => $new->id];
        $this->assertSame('old-db.db.internal', $host($restore));
    }
}
