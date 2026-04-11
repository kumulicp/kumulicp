<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\AppPlan;
use App\Organization;
use App\Support\Facades\Application;
use App\Task;

class UpdateSubscriptionSettings extends Action
{
    public $slug = 'update_subscription_settings';

    public $background = true;

    public function __construct(AppPlan $plan)
    {
        $this->organization = Organization::where('type', 'superaccount')->first();
        $this->description = __('actions.updating_subscription_settings');

        $this->setCustomValues(['plan' => $plan->id]);
    }

    public static function run(Task $task)
    {
        $plan = AppPlan::find($task->getValue('plan'));

        foreach ($plan->subscribers as $subscriber_app) {
            $app_features = Application::instance($subscriber_app)->features();
            $app_features->rebuild();
        }

        $task->complete();
        $task->notified();
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;
        $price = $task->getValue('price');
        $description = $task->getValue('description');

        return new self($organization, $description, $price);
    }

    public static function complete(Task $task) {}
}
