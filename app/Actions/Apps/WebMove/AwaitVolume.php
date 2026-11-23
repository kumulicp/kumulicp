<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\Steps\AwaitConfirmation;
use App\Server;
use App\Support\StorageHandover;
use App\Task;
use Illuminate\Validation\Rule;

/**
 * Waits for the app's volume to be restored on the new cluster, by hand: from the backup of the old
 * one, under a claim of its own (a restore that comes back under the name the chart would make
 * collides with the claim the chart makes). The administrator says what it is called, or that the
 * app is to start with a new, empty one, and that is what the chart is given (StorageHandover).
 */
class AwaitVolume extends AwaitConfirmation
{
    protected function message(Task $task): string
    {
        return __('messages.web_move.await_volume', [
            'server' => Server::find($task->getValue('target_server_id'))?->name,
        ]);
    }

    // What the chart can be given, and what the access mode can be
    public static function form(Task $task): ?array
    {
        return [
            'type' => 'storage',
            'fields' => StorageHandover::fields($task->app_instance),
            'access_modes' => StorageHandover::ACCESS_MODES,
        ];
    }

    public static function rules(Task $task): array
    {
        $rules = StorageHandover::rules();

        // A chart that can't be given a volume that exists can only start with a new one
        if (! StorageHandover::supports($task->app_instance)) {
            $rules['volume'] = ['required', Rule::in(['new'])];
        }

        return $rules;
    }
}
