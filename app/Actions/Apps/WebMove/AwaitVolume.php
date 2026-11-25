<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Steps\AwaitConfirmation;
use App\Server;
use App\Task;

/**
 * Waits for the app's volume to be restored on the new cluster, by hand: from the backup of the old
 * one, under a claim of its own (a restore that comes back under the name the chart would make
 * collides with the claim the chart makes).
 *
 * What the chart is to be given (StorageHandover) is chosen when the move is started, and is on the
 * task (`storage`); an app that is to start with a new, empty volume has nothing to restore, so it
 * does not wait at all. For an existing one, this only waits to be told it has been restored.
 */
class AwaitVolume extends AwaitConfirmation
{
    /**
     * What the chart is to be given, which was chosen when the move was started.
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

        return __('messages.web_move.await_chosen_volume', ['server' => $server, 'claim' => static::chosen($task)['claim'] ?? '']);
    }
}
