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
});
