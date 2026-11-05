<?php

use App\Actions\NativeAction;
use App\Enums\TaskErrorCode;
use App\Enums\TaskStatus;
use App\Integrations\ServerManagers\DockerMailServer\MailserverJobs;
use App\Integrations\ServerManagers\Rancher\Actions\RunJob;
use App\Integrations\ServerManagers\Rancher\Charts\Job\NextcloudJobChart;
use App\OrgDomain;
use App\Organization;
use App\OrgServer;
use App\Support\Facades\Action;
use App\Task;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

/**
 * RunJob keeps the chart it runs as the class and the ids it is built from,
 * never as a serialized object.
 */
function runJobFixture(): array
{
    (new TestSupports)->seed();
    Queue::fake();

    $organization = Organization::find(1);
    $domain = OrgDomain::factory()->create(['organization_id' => $organization->id]);
    $org_server = new OrgServer;
    $org_server->forceFill(['id' => 5]);
    $org_server->setRelation('organization', $organization);

    return [$organization, $domain, $org_server];
}

it('stores the chart as its class and the ids it is built from', function () {
    [, $domain, $org_server] = runJobFixture();

    $values = (new RunJob($org_server, new MailserverJobs($domain), 'add_dkim_key', 'mail'))->customValues();

    expect($values)->not->toHaveKey('job');
    expect($values['chart'])->toBe(MailserverJobs::class);
    expect($values['chart_args'])->toBe(['org_domain_id' => $domain->id]);
    expect($values['job_name'])->toBe('add_dkim_key');
    expect($values['namespace'])->toBe('mail');
});

it('builds the chart again from what the task stored', function () {
    [$organization, $domain, $org_server] = runJobFixture();
    $task = Action::execute(new RunJob($org_server, new MailserverJobs($domain), 'add_dkim_key', 'mail'));

    $chart = RunJob::chart($task->fresh());

    expect($chart)->toBeInstanceOf(MailserverJobs::class);
    expect($chart->domain->is($domain))->toBeTrue();
});

it('refuses a chart that cannot be stored this way', function () {
    [, , $org_server] = runJobFixture();

    expect(fn () => new RunJob($org_server, (new ReflectionClass(NextcloudJobChart::class))->newInstanceWithoutConstructor(), 'upgrade'))->toThrow(InvalidArgumentException::class);
});

it('never builds a class from the database that is not a rebuildable job chart', function () {
    [$organization] = runJobFixture();

    foreach ([stdClass::class, NativeAction::class, 'No\\Such\\Class', null, ['a']] as $chart) {
        $task = new Task;
        $task->forceFill(['organization_id' => $organization->id, 'custom_values' => ['chart' => $chart]]);

        expect(RunJob::chart($task))->toBeNull();
    }
});

it('fails clearly, without touching the cluster, a task made before charts were stored this way', function () {
    [$organization] = runJobFixture();

    $task = new Task;
    $task->forceFill([
        'organization_id' => $organization->id,
        'action_group' => 'rancher',
        'action_slug' => 'run_rancher_job',
        'task_group' => 1,
        'status' => TaskStatus::IN_PROGRESS,
        'description' => 'Add dkim key',
        'custom_values' => ['job' => 'O:8:"stdClass":0:{}', 'job_name' => 'add_dkim_key', 'org_server_id' => 5],
    ]);
    $task->save();

    $result = RunJob::handle($task);

    expect($result->outcome)->toBe('fail');
    expect($result->code)->toBe(TaskErrorCode::JOB_UNAVAILABLE->value);
    expect(RunJob::retry($task))->toBeNull();
});

it('uses no serialize or unserialize on database contents anywhere in the application', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('/(?<![\w>:])(un)?serialize\s*\(/', $line) && ! preg_match('/^\s*(\/\/|\*|#)/', $line)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
