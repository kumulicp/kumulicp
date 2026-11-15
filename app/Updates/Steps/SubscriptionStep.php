<?php

namespace App\Updates\Steps;

use App\Actions\ActionResult;
use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Task;
use App\Updates\UpdateStep;

/** Brings the organization's subscription up to date with what the plan charges now */
class SubscriptionStep extends UpdateStep
{
    public function key(): string
    {
        return 'subscription';
    }

    public function label(): string
    {
        return __('admin.update_steps.subscription.label');
    }

    public function description(): string
    {
        return __('admin.update_steps.subscription.description');
    }

    public function aspects(): array
    {
        return ['subscription'];
    }

    public function checkedByDefault(): bool
    {
        return true;
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        $organization = $app_instance->organization;
        $update = Action::execute(new SubscriptionUpdate($organization, new SubscriptionService($organization)), $task, true);

        return $update ? ActionResult::wait()->waitingFor($update) : ActionResult::done();
    }
}
