<?php

use App\Console\Calls\ActivateBackup;
use App\Console\Calls\CheckVersionUpdates;
use App\Console\Calls\DeactivatedAppInstanceCheck;
use App\Console\Calls\DeleteBackups;
use App\Console\Calls\DispatchTasks;
use App\Console\Calls\ExpiredSubscriptionsCheck;
use App\Console\Calls\NextcloudStorageChecks;
use App\Console\Calls\NotifyBillingManagers;
use App\Console\Calls\RecurringBackups;
use App\Integrations\Registrars\Namecheap\Jobs\UpdateTransferStatus;
use App\Services\VersionTesting\VersionTestRunner;
use Illuminate\Support\Facades\Schedule;
use Spatie\UptimeMonitor\Commands\CheckCertificates;
use Spatie\UptimeMonitor\Commands\CheckUptime;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// One ordered pass moves every task along (poll, ready, queue, reap). It is
// single-flight: a slow pass must not overlap the next minute's (two passes
// would claim the same tasks), and only one server runs it. The mutex expiry
// keeps a crashed run from blocking tasks for the default 24 hours.
Schedule::call(new DispatchTasks)->name('task-dispatcher')->everyMinute()->withoutOverlapping(15)->onOneServer();
Schedule::call(fn () => app(VersionTestRunner::class)->syncProvisioning())->name('version-test-sync')->everyMinute()->withoutOverlapping(5);
Schedule::call(new RecurringBackups)->everyMinute();
Schedule::call(new UpdateTransferStatus)->everyMinute();
Schedule::call(new NextcloudStorageChecks)->daily();
Schedule::call(new ActivateBackup)->everyMinute();
Schedule::call(new DeleteBackups)->everyMinute();
Schedule::call(new NotifyBillingManagers)->daily();
Schedule::call(new ExpiredSubscriptionsCheck)->everyMinute();
Schedule::call(new DeactivatedAppInstanceCheck)->everyMinute();
Schedule::call(new CheckVersionUpdates)->daily()->at('03:00');
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command('queue:prune-batches')->daily();
Schedule::command(CheckUptime::class)->everyMinute();
Schedule::command(CheckCertificates::class)->daily();
