<?php

use App\Organization;
use App\Support\TaskHelpers;
use App\Task;

function statusTask(array $attributes = []): Organization
{
    $organization = Organization::factory()->create();

    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => $organization->id,
        'action_group' => 'status',
        'action_slug' => 'native',
        'task_group' => 1,
        'status' => 'in_progress',
        'description' => 'Status test task',
        'background' => 0,
    ], $attributes));
    $task->save();

    return $organization;
}

function customerStatus(Organization $organization): string
{
    return (new TaskHelpers)->getGroupStatus(['organization_id' => $organization->id, 'background' => 0])[0]['status'];
}

it('does not tell customers a task is having trouble just because it has run more than once', function (array $attributes) {
    expect(customerStatus(statusTask($attributes)))->toBe('In Progress');
})->with([
    'first run of an action with postGenerate' => [['attempts' => 2]],
    'many routine waiting polls' => [['attempts' => 9, 'error_code' => 'waiting', 'error_message' => 'Waiting for other tasks to complete']],
    'waiting to be polled again' => [['status' => 'pending', 'attempts' => 3, 'error_code' => 'waiting']],
]);

it('tells customers a task is having trouble when it is being retried after an error', function (string $code) {
    expect(customerStatus(statusTask(['status' => 'pending', 'attempts' => 2, 'error_code' => $code, 'error_message' => 'boom'])))->toBe('Failed');
})->with(['connection_failed', 'error']);

it('tells customers a failed or blocked task is having trouble', function (string $status) {
    expect(customerStatus(statusTask(['status' => $status, 'blocked_from' => $status === 'blocked' ? 'pending' : null])))->toBe('Failed');
})->with(['failed', 'blocked']);
