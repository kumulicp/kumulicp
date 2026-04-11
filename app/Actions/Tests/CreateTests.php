<?php

namespace App\Actions\Tests;

use App\AccountTest;
use App\Actions\Action;
use App\Actions\Apps\ApplicationActivate;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Prerequisites;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Plan;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Organization;
use App\Support\Facades\Settings;
use App\Task;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

class CreateTests extends Action
{
    public $plan;

    public $slug = 'create_tests';

    public function __construct(private AccountTest $test)
    {
        $this->description = __('actions.create_tests', ['number' => $test->test_number]);
        $this->organization = Organization::where('type', 'superaccount')->first();

        $prereqs = new Prerequisites;
        $prereqs->add_parent_task('action_slug', 'clear_tests', 'yes');

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

        // Get last used test account #
        $test = \App\Organization::whereNotNull('account_test_id')->whereNot('account_test_id', 0)->orderBy('id', 'desc')->first();

        if ($test) {
            $last_test = (int) $test->contact_last_name;
        } else {
            $last_test = (App::environment('production')) ? 10 : 0;
        }

        for ($n = 1; $n < $test_account->test_number + 1; $n++) {
            $num = $n + $last_test;
            $test_name = 'test'.$num;
            $organization = new \App\Organization;
            $organization->slug = $test_name;
            $organization->name = $test_name;
            $organization->description = $test_name;
            $organization->contact_first_name = 'Test';
            $organization->contact_last_name = $num;
            $organization->contact_email = $test_name.'@'.Settings::get('base_domain');
            $organization->contact_phone_number = '123-456-7890';
            $organization->street = '123 Street St';
            $organization->zipcode = '123 456';
            $organization->city = 'Town';
            $organization->state = 'AZ';
            $organization->country = 'US';
            $organization->secretpw = Str::password(20, true, true, false, false);
            $organization->api_token = Str::password(20, true, true, false, false);
            $organization->status = 'active';
            $organization->account_test_id = $test_account->id;
            $organization->plan_id = $base_plan->id;
            $organization->save();

            // Create base domain
            $organization_service = Organization::setOrganization($organization);

            $account = AccountManager::accounts()->create($organization);
            $user = AccountManager::users()->add([
                'username' => $test_name,
                'first_name' => $test_name,
                'last_name' => $test_name,
                'name' => $test_name,
                'email' => $test_name.'@'.Settings::get('base_domain') ?? 'example.com',
                'password' => 'thisisadumbpassword',
                'phone_number' => '1234567890',
            ]);
            $user->permissions()->addControlPanelAccess(organization: $organization);

            if ($userModel = $organization->users()->where('username', $test_name)->first()) {
                $organization->primary_contact()->associate($userModel);
                $organization->save();
            }

            $subscription = (new SubscriptionService($organization))->all();
            if ($base_plan) {
                $task = ActionFacade::execute(new SubscriptionUpdate($organization, $subscription));
                $waiting_for[] = $task->id;
            }

            foreach ($test_account->settings['apps'] as $name => $app) {
                if ($app['plan']) {
                    $application = Application::where('slug', $name)->first();
                    $plan = AppPlan::find($app['plan']);
                    $version = $app['version'] ? AppVersion::find($app['version']) : $application->active_version();
                    $app = ActionFacade::execute(new ApplicationActivate(
                        organization: $organization,
                        app: $application,
                        version: $version,
                        plan: $plan,
                    ));

                    $waiting_for[] = $app->id;
                }
            }
        }

        $self = new self($test_account);
        $self->addCustomValue(['waiting_for' => $waiting_for]);

        return $self;
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
