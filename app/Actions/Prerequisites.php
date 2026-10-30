<?php

namespace App\Actions;

use App\AppInstance;
use App\Application;
use App\OrgDomain;
use App\Services\Tasks\TaskDependencies;
use App\Task;

class Prerequisites
{
    private $prereqs = [];

    public $passed = true;

    public $permanent_fail = false;

    // A task this one waits for has failed, so waiting longer won't help
    public $blocked = false;

    public $message = '';

    public function __construct(private ?Task $task = null) {}

    public function get($return = 'json')
    {

        $array = ['prereqs' => $this->prereqs];

        return ($return == 'array')
            ? $array
            : json_encode($array);
    }

    public function check(string $prerequisite_name, array $prerequisite_vars)
    {
        $prereq_check = "{$prerequisite_name}_check";
        if (method_exists($this, $prereq_check)) {
            $this->$prereq_check($prerequisite_vars);
        }
    }

    /**
     * The tasks the task waits for to start: has one failed, or are any still
     * on their way?
     */
    public function checkDependencies(): static
    {
        if (! $this->task) {
            return $this;
        }

        $state = app(TaskDependencies::class)->state($this->task, Task::GATE_START);

        if ($state['failed']->isNotEmpty()) {
            $this->passed = false;
            $this->blocked = true;
            $this->message .= __('messages.action.dependency_failed');
        } elseif ($state['waiting']->isNotEmpty()) {
            $this->passed = false;
            $this->message .= __('messages.action.waiting_for');
        }

        return $this;
    }

    public static function parser($instructions, $vars = [])
    {

        $instructions = json_decode($instructions, true);
        $new_array = [];

        if (! $instructions || ! isset($instructions['prereqs']) || ! is_array($instructions['prereqs'])) {
            return [];
        }

        foreach ($instructions['prereqs'] as $prereq) {
            $new_prereq = [];
            foreach ($prereq as $key => $value) {
                if (array_key_exists($value, $vars)) {
                    $new_prereq[$key] = $vars[$value];
                } else {
                    $new_prereq[$key] = $value;
                }
            }

            $new_array[] = $new_prereq;
        }

        return $new_array;
    }

    public function add_from_template($template, $values)
    {
        $this->add_prereqs(self::parser($template, $values));

        return $this;
    }

    public function add_from_json($json)
    {
        $prereqs = json_decode($json, true);

        if (is_array($prereqs)) {

            $this->add_prereqs($prereqs);

        }

        return $this;
    }

    public function add_subscription_active()
    {
        $this->prereqs[] = [
            'type' => 'subscription_active',
        ];

        return $this;
    }

    private function subscription_active_check($prereq)
    {
        if (! $this->task->organization->plan) {
            $this->passed = false;
            $this->message .= __('messages.action.need_subscription');
        }

        return $this;
    }

    public function add_application_required(AppInstance $app)
    {
        $this->prereqs[] = [
            'type' => 'application_required',
            'app_id' => $app->id,
        ];

        return $this;
    }

    public function add_confirm_domain(OrgDomain $domain, string $type, ?AppInstance $app_instance = null)
    {
        $this->prereqs[] = [
            'type' => 'confirm_domain',
            'domain_id' => $domain->id,
            'domain_type' => $type,
            'app_instance_id' => $app_instance ? $app_instance->id : null,
        ];

        return $this;
    }

    private function application_required_check($prereq)
    {
        $app = AppInstance::find($prereq['app_id']);

        if ($app && $app->status !== 'active') {
            $this->passed = false;
            $this->message .= __('messages.action.parent_app', ['app' => $app->label]);
        }

        return $this;
    }

    public function add_time_range($start, $end, $range = 'hours')
    {
        $this->prereqs[] = [
            'type' => 'time_range',
            'range' => $range,
            'start' => $start,
            'end' => $end,
        ];

        return $this;
    }

    private function time_range_check($prereq)
    {
        $current = date_create_from_format('H:i', date('H:i', time()));
        if ($prereq['range'] == 'hours') {
            $start = date_create_from_format('H:i', $prereq['start']);
            $end = date_create_from_format('H:i', $prereq['end']);
            if ($prereq['end'] == '00:00') {
                $end = date_create_from_format('H:i', '23:59');
            }
            if ($current > $start && $current < $end) {

            } else {
                $this->passed = false;
                $this->message .= __('messages.action.time_range', ['start' => $prereq['start'], 'end' => $prereq['end']]);
            }
        }
    }

    private function application_active_check()
    {
        $app = Application::where('id', $this->task->application_id)->first();

        if ($app['status'] == 'disable') {
            $this->passed = false;
            $this->permanent_fail = true;
        }

        return $this;
    }

    private function app_instance_status_check()
    {
        $app_instance = AppInstance::where('application_id', $this->task->application_id)
            ->where('organization_id', $this->task->organization_id)
            ->first();

        if ($app_instance['status'] == 'failed') {
            $this->passed = false;
            $this->permanent_fail = true;
        }

        return $this;
    }

    private function add_prereqs($prereqs)
    {
        foreach ($prereqs as $prereq) {

            $this->prereqs[] = $prereq;

        }

        return $this;

    }
}
