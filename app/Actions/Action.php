<?php

namespace App\Actions;

use App\Enums\TaskStatus;
use App\Task;
use Illuminate\Support\Arr;

class Action
{
    public TaskStatus $status = TaskStatus::PENDING;

    public $background = false;

    public $description = '';

    public $custom_values = [];

    public $custom_action;

    public $action_group = 'system';

    public $prerequisites;

    public $task;

    public $error = false;

    public $replace = false;

    public $error_data = [];

    public $organization;

    public $slug = '';

    // Minutes after the task is created by which it must be complete. Past that
    // the TaskDispatcher rolls it back and fails it. Null: no deadline.
    public ?int $deadline_minutes = null;

    // How many times the runner tries the task when it throws an error it doesn't
    // know. 1: fail at once, as an action that isn't safe to run twice must.
    public static int $max_attempts = 1;

    // Seconds to wait before running the task again after a transient failure;
    // the last one repeats.
    public static array $backoff = [60, 120, 300, 600];

    // Routes RunAction onto the 'database-long' queue connection (longer retry_after).
    public static bool $long_running = false;

    /**
     * Ids of the tasks that must be complete before this one runs.
     *
     * @var array<int, int>
     */
    public array $start_dependencies = [];

    /**
     * Ids of the tasks this one can't complete without, or null if the action
     * doesn't say (a running task's existing ones are then left as they are).
     *
     * @var array<int, int>|null
     */
    public ?array $finish_dependencies = null;

    protected $application;

    protected $version;

    protected $app_instance;

    /**
     * The task isn't run until these are complete. A failed one blocks it.
     */
    public function dependsOn(Task ...$tasks): static
    {
        $this->start_dependencies = array_values(array_unique([...$this->start_dependencies, ...array_map(fn (Task $task) => $task->id, $tasks)]));

        return $this;
    }

    /**
     * The task stays in progress, and isn't polled for completion, until these
     * are complete. For work the action started and is waiting on. Replaces what
     * a running task waited on before.
     */
    public function waitFor(Task ...$tasks): static
    {
        $this->finish_dependencies = array_values(array_unique([...($this->finish_dependencies ?? []), ...array_map(fn (Task $task) => $task->id, $tasks)]));

        return $this;
    }

    public function application($property)
    {
        if ($this->application) {
            return $this->application->$property;
        } elseif ($this->app_instance) {
            return $this->app_instance->application->$property;
        }
    }

    public function version($property)
    {
        if ($this->version) {
            return $this->version->$property;
        } elseif ($this->app_instance) {
            return $this->app_instance->version->$property;
        }
    }

    public function app_instance($property)
    {
        if ($this->app_instance) {
            return $this->app_instance->$property;
        }
    }

    public function addCustomValue($values = [])
    {
        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $this->custom_values = array_merge($this->custom_values, $values);

        return $this;
    }

    public function setCustomValues($values)
    {
        $this->custom_values = $values;

        return $this;
    }

    public function customValues($format = 'array')
    {

        switch ($format) {
            case 'json':
                return json_encode($this->custom_values);

            case 'object':
                return (object) $this->custom_values;

            case 'array':
                return $this->custom_values;

            default:
                return $this->custom_values;
        }

    }

    public function getValue($key)
    {
        return Arr::get($this->custom_values, $key);
    }

    public function setAppInstance($app_instance)
    {
        $this->app_instance = $app_instance;

        return $this;
    }

    public function task_exists()
    {
        // Check if the same system task is already in progress
        $task = Task::where('organization_id', $this->organization->id)
            ->where('action_slug', $this->slug)
            ->where('action_group', $this->action_group)
            ->whereIn('status', TaskStatus::openValues());

        // If action doesn't replace previous action, also confirm the custom values are the same to avoid redundancy
        if (! $this->replace) {
            foreach ($this->customValues() as $key => $value) {
                $task->where("custom_values->$key", $value);
            }
        }

        if ($this->app_instance) {
            $task->where('app_instance_id', $this->app_instance->id);
        }
        $task = $task->first();

        // If this action should be replace, delete old task
        if ($task && $this->replace) {
            $task->delete();
            $task = false;
        }

        return $task ? true : false;
    }
}
