<?php

namespace App\Updates\Steps;

use App\Actions\ActionResult;
use App\AppInstance;
use App\Integrations\Applications\AppFeature;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Task;
use App\Updates\Aspects\FeatureAspect;
use App\Updates\UpdateStep;

/**
 * Runs the action a feature of the app brings (initial setup, configs...).
 * Every feature with an action gets one, so a plugin adds its action to its
 * feature and it is on the list.
 */
class FeatureActionStep extends UpdateStep
{
    public function __construct(private readonly string $feature, private readonly string $title) {}

    public static function for(AppFeature $feature): ?self
    {
        $action = $feature->action ?? null;

        return $action && class_exists($action) ? new self($feature->name, $feature->label ?: $feature->name) : null;
    }

    public function key(): string
    {
        return 'feature.'.$this->feature;
    }

    public function label(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return __('admin.update_steps.feature.description');
    }

    public function aspects(): array
    {
        return [FeatureAspect::keyFor($this->feature)];
    }

    // Once the app has what its version needs
    public function after(): array
    {
        return ['release', 'settings-job', 'ldap-groups', 'sso'];
    }

    public function afterUpgrade(AppInstance $app_instance): bool
    {
        return (bool) $app_instance->version?->customizations;
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        $feature = Application::instance($app_instance)->features()->all()[$this->feature] ?? null;
        $action = $feature['action'] ?? null;

        if (! $action || ! class_exists($action)) {
            return ActionResult::done();
        }

        $started = Action::execute(new $action($app_instance, [
            'name' => $feature['name'],
            'status' => $feature['status'],
            'settings' => $feature['settings'],
        ]), $task, true);

        return $started ? ActionResult::wait()->waitingFor($started) : ActionResult::done();
    }
}
