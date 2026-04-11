<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class Task extends Model
{
    protected $table = 'tasks';

    protected $casts = [
        'custom_values' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    public function version()
    {
        return $this->belongsTo('App\AppVersion', 'version_id');
    }

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function app_instance()
    {
        return $this->belongsTo('App\AppInstance', 'app_instance_id');
    }

    public function action()
    {
        $task_action_group = $this->action_group;
        $task_action_slug = $this->action_slug;
        $action = "$task_action_group.$task_action_slug";

        return $action;
    }

    public function groupNotified()
    {
        if ($this->task_group) {
            $tasks = Task::where('task_group', $this->task_group)
                ->update(['notified' => 1]);
        }
    }

    public function notified()
    {
        $this->notified = 1;
        $this->save();
    }

    public function customValues($format = 'array')
    {

        switch ($format) {
            case 'json':
                return $this->custom_values;
                break;

            case 'object':
                return (object) $this->custom_values;
                break;

            case 'array':
                return is_array($this->custom_values) ? $this->custom_values : json_decode($this->custom_values, true);
                break;

            default:
                return $this->custom_values;
                break;
        }

    }

    public function getValue($key)
    {
        return Arr::get($this->custom_values, $key, null);
    }

    public function restart()
    {
        $this->status = 'pending';
        $this->job_id = null;
        $this->save();
    }

    public function complete()
    {
        $this->status = 'complete';
        $this->error_message = '';
        $this->save();
    }
}
