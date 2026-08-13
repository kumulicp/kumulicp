<?php

use App\BackupSchedule;
use App\Console\Calls\DeleteBackups;
use App\OrgBackup;
use App\Organization;
use App\RecurringBackup;
use App\Server;
use App\Support\Facades\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeCompletedBackup(Organization $organization, RecurringBackup $recurringBackup, $completedAt)
{
    $schedule = BackupSchedule::create([
        'recurring_backup_id' => $recurringBackup->id,
        'scheduled_at' => $completedAt,
    ]);

    return OrgBackup::create([
        'organization_id' => $organization->id,
        'scheduled_backup_id' => $schedule->id,
        'action' => 'backup',
        'type' => 'default',
        'status' => 'completed',
        'scheduled_at' => $completedAt,
        'completed_at' => $completedAt,
        'delete_at' => null,
    ]);
}

it('deletes backups beyond the configured count across multiple scheduled runs', function () {
    $organization = Organization::factory()->create();
    $server = Server::factory()->create();

    $recurringBackup = RecurringBackup::create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'delete_after' => 2,
        'delete_interval' => 'backups',
        'status' => 'active',
        'time' => '00:00',
    ]);

    // 4 completed backups from 4 different scheduled runs, oldest to newest.
    $backups = [];
    for ($i = 4; $i >= 1; $i--) {
        $backups[] = makeCompletedBackup($organization, $recurringBackup, now()->subDays($i));
    }

    Backup::shouldReceive('connect')->twice()->andReturnSelf();
    Backup::shouldReceive('delete')->twice()->andReturn(['job_id' => 'job-123']);

    (new DeleteBackups)();

    // The 2 newest backups should be kept (job_id untouched, status still completed).
    expect($backups[2]->fresh()->status)->toBe('completed');
    expect($backups[3]->fresh()->status)->toBe('completed');

    // The 2 oldest backups should have had delete() called on them.
    expect($backups[0]->fresh()->job_id)->toBe('job-123');
    expect($backups[1]->fresh()->job_id)->toBe('job-123');
    expect($backups[0]->fresh()->status)->toBe('deleting');
    expect($backups[1]->fresh()->status)->toBe('deleting');
});

it('does not redispatch delete for a backup already awaiting deletion confirmation', function () {
    $organization = Organization::factory()->create();
    $server = Server::factory()->create();

    $recurringBackup = RecurringBackup::create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'delete_after' => 0,
        'delete_interval' => 'backups',
        'status' => 'active',
        'time' => '00:00',
    ]);

    $backup = makeCompletedBackup($organization, $recurringBackup, now()->subDays(1));

    // Regardless of how many times the (every-minute-scheduled) job runs while
    // waiting on the external delete webhook, delete() should only fire once.
    Backup::shouldReceive('connect')->once()->andReturnSelf();
    Backup::shouldReceive('delete')->once()->andReturn(['job_id' => 'job-123']);

    (new DeleteBackups)();
    (new DeleteBackups)();
    (new DeleteBackups)();

    expect($backup->fresh()->status)->toBe('deleting');
});

it('keeps a full run intact when multiple app instances share one scheduled run', function () {
    $organization = Organization::factory()->create();
    $server = Server::factory()->create();

    $recurringBackup = RecurringBackup::create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'delete_after' => 2,
        'delete_interval' => 'backups',
        'status' => 'active',
        'time' => '00:00',
    ]);

    // 2 scheduled runs, each backing up 3 app instances (3 OrgBackup rows per run).
    $runs = [];
    for ($i = 2; $i >= 1; $i--) {
        $completedAt = now()->subDays($i);
        $schedule = BackupSchedule::create([
            'recurring_backup_id' => $recurringBackup->id,
            'scheduled_at' => $completedAt,
        ]);

        $runs[] = collect(range(1, 3))->map(fn () => OrgBackup::create([
            'organization_id' => $organization->id,
            'scheduled_backup_id' => $schedule->id,
            'action' => 'backup',
            'type' => 'default',
            'status' => 'completed',
            'scheduled_at' => $completedAt,
            'completed_at' => $completedAt,
            'delete_at' => null,
        ]));
    }

    Backup::shouldReceive('connect')->never();
    Backup::shouldReceive('delete')->never();

    (new DeleteBackups)();

    // With delete_after=2 "backups" (runs), both runs are within the retention
    // window even though they total 6 rows, so nothing should be deleted yet.
    foreach ($runs as $run) {
        foreach ($run as $backup) {
            expect($backup->fresh()->status)->toBe('completed');
            expect($backup->fresh()->job_id)->toBeNull();
        }
    }
});
