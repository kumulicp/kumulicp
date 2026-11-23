<?php

namespace App\Actions\Apps\ServerMove;

use App\Actions\Steps\StepAction;
use App\AppInstance;
use App\OrgServer;
use App\Server;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * What the moves of an app's server have in common (docs/server-transitions-plan.md):
 * the app is going to a target `Server`, is marked as updating while the task runs, and
 * a failure puts it back and says where it is. A move declares which of the app's
 * servers it moves, words its description, and lists its steps. The first of them
 * is normally a PrepareMove, which records where the app is coming from and going to.
 *
 * ApplicationDatabaseMigrate is the first, and the reference for how the steps of a move
 * are written.
 */
abstract class ApplicationServerMove extends StepAction
{
    /**
     * Which of the app's servers is moved: 'database', 'web' or 'sso'.
     */
    abstract public static function serverType(): string;

    /**
     * What the task says it is doing.
     */
    abstract protected function describe(AppInstance $app_instance, Server $target_server): string;

    /**
     * Where the messages of the move are: messages.<group>.rolled_back and
     * rollback_failed. They are given :server, :reason, :error and :type.
     */
    protected static function messageGroup(): string
    {
        return 'server_move';
    }

    // Pure: nothing is touched until the task really exists (see onCreate())
    public function __construct(AppInstance $app_instance, Server $target_server)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->addCustomValue(['target_server_id' => $target_server->id]);
        $this->description = $this->describe($app_instance, $target_server);
    }

    // Only once the task really exists (not for a duplicate that
    // Action::execute() turns away)
    public function onCreate(Task $task)
    {
        static::begin($task);
    }

    protected static function begin(Task $task): void
    {
        $task->app_instance->status = 'updating';
        $task->app_instance->save();
    }

    public static function reset(Task $task): void
    {
        // Starts over; the previous attempt already rolled itself back, so only
        // where it was going stays.
        $task->custom_values = Arr::only($task->customValues(), ['target_server_id']);

        static::begin($task);
    }

    /**
     * Says where the app is back on.
     */
    public static function failureMessage(Task $task, string $reason, array $notes, array $errors): string
    {
        $group = static::messageGroup();
        $type = static::serverType();

        if ($errors) {
            $message = __("messages.{$group}.rollback_failed", ['reason' => $reason, 'error' => $errors[0], 'type' => $type]);
        } else {
            $previous = OrgServer::find($task->getValue('previous_org_server_id'));

            $message = __("messages.{$group}.rolled_back", [
                'server' => $previous?->server?->name,
                'reason' => $reason,
                'type' => $type,
            ]);

            if ($notes) {
                $message .= ' '.implode(' ', $notes);
            }
        }

        Log::critical($message, ['organization_id' => $task->organization_id]);

        return $message;
    }
}
