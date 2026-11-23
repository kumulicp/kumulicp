<?php

namespace App\Actions\Apps\SsoMove;

use App\Actions\ActionResult;
use App\Actions\Steps\Step;
use App\Task;
use Illuminate\Support\Arr;

/**
 * Saves the app's sign-in settings on the task, and clears them. They are the old server's
 * provider and application (`sso`) and the client id and secret made for them: setting up the
 * new server starts from nothing, and would otherwise look for the old provider's id there.
 *
 * Undone by putting the saved settings back, which is also what a later rollback needs the old
 * server to see. The old server's own entries are never touched.
 */
class ClearSso extends Step
{
    public const KEYS = ['sso', 'sso_client_id', 'sso_client_secret'];

    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;
        $settings = $app_instance->settings ?? [];

        // Saved before anything is removed, so that a crash in between loses nothing
        $task->custom_values = array_merge($task->customValues(), ['sso_snapshot' => Arr::only($settings, self::KEYS)]);
        $task->save();

        $app_instance->settings = Arr::except($settings, self::KEYS);
        $app_instance->save();

        return ActionResult::done();
    }

    public function rollback(Task $task): ?string
    {
        $snapshot = $task->getValue('sso_snapshot');

        // Never got as far as saving them, so nothing was cleared
        if (! is_array($snapshot)) {
            return null;
        }

        $app_instance = $task->app_instance;
        // What the new server added goes, what the old one had comes back
        $app_instance->settings = Arr::except($app_instance->settings ?? [], self::KEYS) + $snapshot;
        $app_instance->save();

        return null;
    }
}
