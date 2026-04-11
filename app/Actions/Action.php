<?php

namespace App\Actions;

use App\Task;
use Illuminate\Support\Arr;

class Action
{
    public $status = 'pending';

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

    protected $application;

    protected $version;

    protected $app_instance;

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
                break;

            case 'object':
                return (object) $this->custom_values;
                break;

            case 'array':
                return $this->custom_values;
                break;

            default:
                return $this->custom_values;
                break;
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

    private function custom_values()
    {
        if (isset($this->task) && $this->task->custom_values) {

            $decode = json_decode($this->task->custom_values);

            return $decode;

        }

        return null;
    }

    public function task_exists()
    {
        // Check if the same system task is already in progress
        $task = Task::where('organization_id', $this->organization->id)
            ->where('action_slug', $this->slug)
            ->where('action_group', $this->action_group)
            ->where('status', '!=', 'complete');

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
