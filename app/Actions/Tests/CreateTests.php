<?php

namespace App\Actions\Tests;

use App\AccountTest;
use App\Actions\Action;
use App\Actions\Apps\ApplicationActivate;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Plan;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Organization;
use App\Task;
use Illuminate\Support\Arr;

class CreateTests extends Action
{
    public $plan;

    public $slug = 'create_tests';

    public function __construct(private AccountTest $test)
    {
        $this->description = __('actions.create_tests', ['number' => $test->test_number]);
        $this->organization = Organization::where('type', 'superaccount')->first();

        $this->setCustomValues(['test_id' => $test->id]);

        $default_plan = Plan::where('is_default', 1)->first();
        if (! $default_plan) {
            throw new \Exception('There is no default plan set!');
        }
    }

    public static function run(Task $task)
    {
        $test_account = AccountTest::find($task->getValue('test_id'));
        $base_plan = Plan::find($test_account->setting('base_plan'));
        $waiting_for = [];

        $last_test = CreateTestOrganization::lastNumber();

        for ($n = 1; $n < $test_account->test_number + 1; $n++) {
            [$organization, $subscription_task] = (new CreateTestOrganization)->create($test_account, $base_plan, $n + $last_test);

            if ($base_plan) {
                $waiting_for[] = $subscription_task;
            }

            foreach ($test_account->settings['apps'] as $name => $app) {
                if (Arr::get($app, 'plan')) {
                    $application = Application::where('slug', $name)->first();
                    $plan = AppPlan::find($app['plan']);
                    $version = Arr::get($app, 'version') ? AppVersion::find($app['version']) : $application->active_version();
                    $app = ActionFacade::execute(new ApplicationActivate(
                        organization: $organization,
                        app: $application,
                        version: $version,
                        plan: $plan,
                    ));

                    $waiting_for[] = $app;
                }
            }
        }

        return (new self($test_account))->waitFor(...array_filter($waiting_for));
    }

    public static function retry(Task $task)
    {
        return new self(AccountTest::find($task->getValue('test_id')));
    }

    public static function complete(Task $task)
    {
        $task->complete();
        $task->groupNotified();
    }
}
