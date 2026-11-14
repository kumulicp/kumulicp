<?php

use App\Actions\ActionResult;
use App\Enums\TaskErrorCode;
use App\Task;

it('knows an error code the task holds, and none that it does not', function () {
    $task = new Task;

    expect($task->errorCode())->toBeNull();

    $task->error_code = 'connection_failed';
    expect($task->errorCode())->toBe(TaskErrorCode::CONNECTION_FAILED);

    // Free strings from older rows and from actions that were never listed
    $task->error_code = 'something_old';
    expect($task->errorCode())->toBeNull();

    $task->error_code = '';
    expect($task->errorCode())->toBeNull();
});

it('says which codes mean the task is being retried because something went wrong', function () {
    $transient = collect(TaskErrorCode::cases())->filter->isTransient()->map->value->values()->all();

    expect($transient)->toBe(['error', 'connection_failed']);
});

it('stores a code given as an enum or as a string as the same string', function () {
    expect(TaskErrorCode::valueOf(TaskErrorCode::WAITING))->toBe('waiting');
    expect(TaskErrorCode::valueOf('my_code'))->toBe('my_code');
    expect(TaskErrorCode::valueOf(null))->toBeNull();
    expect(ActionResult::fail('x', TaskErrorCode::APP_NOT_FOUND)->code)->toBe('app_not_found');
    expect(ActionResult::fail('x', 'my_code')->code)->toBe('my_code');
});

it('has no error code written as a literal in the application', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('/[\'"]?error_code[\'"]?\s*(=>|=)\s*[\'"][^\'"]+[\'"]/', $line)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'use App\Enums\TaskErrorCode instead of a string');
});
