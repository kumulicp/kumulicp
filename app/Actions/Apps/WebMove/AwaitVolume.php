<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Steps\AwaitConfirmation;
use App\Server;
use App\Support\StorageHandover;
use App\Task;

/**
 * Waits for the app's volume to be restored on the new cluster, by hand: from the backup of the old
 * one, under a claim of its own (a restore that comes back under the name the chart would make
 * collides with the claim the chart makes).
 *
 * What the chart is to be given (StorageHandover) is chosen when the move is started, and is on the
 * task (`storage`); an app that is to start with a new, empty volume has nothing to restore, so it
 * does not wait at all. A move started without it asks for it here, with the go-ahead.
 */
class AwaitVolume extends AwaitConfirmation
{
    /**
     * What the chart is to be given, if it was chosen when the move was started.
     *
     * @return array<string, mixed>|null
     */
    public static function chosen(Task $task): ?array
    {
        return $task->getValue('storage');
    }

    public function run(Task $task): ActionResult
    {
        if ((static::chosen($task)['volume'] ?? null) === 'new') {
            return ActionResult::done();
        }

        return parent::run($task);
    }

    protected function message(Task $task): string
    {
        $server = Server::find($task->getValue('target_server_id'))?->name;

        if ($claim = static::chosen($task)['claim'] ?? null) {
            return __('messages.web_move.await_chosen_volume', ['server' => $server, 'claim' => $claim]);
        }

        return __('messages.web_move.await_volume', ['server' => $server]);
    }

    // What the chart can be given, and what the access mode can be: only when it was not chosen
    public static function form(Task $task): ?array
    {
        if (static::chosen($task)) {
            return null;
        }

        return [
            'type' => 'storage',
            'fields' => StorageHandover::fields($task->app_instance),
            'access_modes' => StorageHandover::ACCESS_MODES,
        ];
    }

    public static function rules(Task $task): array
    {
        return static::chosen($task) ? [] : StorageHandover::rulesFor($task->app_instance);
    }
}
