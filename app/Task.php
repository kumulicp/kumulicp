<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * @property int $id
 * @property int|null $application_id
 * @property int|null $version_id
 * @property int|null $organization_id
 * @property int|null $app_instance_id
 * @property string $status
 * @property string|null $error_message
 * @property int|null $job_id
 * @property string|null $action_group
 * @property string|null $action_slug
 * @property string|null $task_group
 * @property int $notified
 * @property array|null $custom_values
 * @property-read \App\Application|null $application
 * @property-read \App\AppVersion|null $version
 * @property-read \App\Organization|null $organization
 * @property-read \App\AppInstance|null $app_instance
 */
class Task extends Model
{
    protected $table = 'tasks';

    protected $casts = [
        'custom_values' => 'array',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Application, $this>
     */
    public function application(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\AppVersion, $this>
     */
    public function version(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo('App\AppVersion', 'version_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Organization, $this>
     */
    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\AppInstance, $this>
     */
    public function app_instance(): \Illuminate\Database\Eloquent\Relations\BelongsTo
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

            case 'object':
                return (object) $this->custom_values;

            case 'array':
                return is_array($this->custom_values) ? $this->custom_values : [];

            default:
                return $this->custom_values;
        }

    }

    public function getValue($key)
    {
        return Arr::get($this->custom_values, $key, null);
    }

    public function restart()
    {
        $this->status = 'pending';
        $this->job_id = 0;
        $this->save();
    }

    public function complete()
    {
        $this->status = 'complete';
        $this->error_message = '';
        $this->save();
    }
}
