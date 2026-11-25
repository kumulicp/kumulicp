<?php

namespace Tests\Support\Steps;

use App\Actions\Steps\AwaitConfirmation;
use App\Task;

/**
 * A step that waits for an administrator, and asks for a claim name with the go-ahead.
 */
class FakeAwaitConfirmation extends AwaitConfirmation
{
    protected function message(Task $task): string
    {
        return 'Restore the volume, then continue';
    }

    public static function rules(Task $task): array
    {
        return ['claim' => ['required', 'string']];
    }
}
