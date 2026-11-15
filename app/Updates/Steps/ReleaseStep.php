<?php

namespace App\Updates\Steps;

use App\Actions\ActionResult;
use App\AppInstance;
use App\Support\Facades\Application;
use App\Task;
use App\Updates\UpdateStep;

/** Applies the app's settings to the release on its web server */
class ReleaseStep extends UpdateStep
{
    public function key(): string
    {
        return 'release';
    }

    public function label(): string
    {
        return __('admin.update_steps.release.label');
    }

    public function description(): string
    {
        return __('admin.update_steps.release.description');
    }

    // A shared child without a site of its own is covered by the hub's release
    public function appliesTo(AppInstance $app_instance): bool
    {
        return $app_instance->usesOwnResources();
    }

    public function aspects(): array
    {
        return ['release'];
    }

    public function checkedByDefault(): bool
    {
        return true;
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        Application::instance($app_instance)->connect('web')->update();

        return ActionResult::wait();
    }

    public function poll(Task $task, AppInstance $app_instance): ActionResult
    {
        $service = Application::instance($app_instance);

        // A site inside a hub is rolled out by the hub's release
        $target = $service->parent ? Application::instance($service->parent) : $service;

        return $target->connect('web')->isActive() ? ActionResult::done() : ActionResult::wait();
    }
}
