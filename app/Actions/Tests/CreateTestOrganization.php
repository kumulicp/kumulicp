<?php

namespace App\Actions\Tests;

use App\AccountTest;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Organization;
use App\Plan;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Settings;
use App\Task;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Creates one throwaway organization, with a user who can log in to its
 * control panel, for an AccountTest. Shared by the ad-hoc tests and the
 * version test runs.
 */
class CreateTestOrganization
{
    public const DEFAULT_PASSWORD = 'thisisadumbpassword';

    /**
     * The highest test number in use, so new test orgs carry on from it.
     */
    public static function lastNumber(): int
    {
        $test = Organization::whereNotNull('account_test_id')->whereNot('account_test_id', 0)->orderBy('id', 'desc')->first();

        if ($test) {
            return (int) $test->contact_last_name;
        }

        return App::environment('production') ? 10 : 0;
    }

    /**
     * @return array{0: Organization, 1: Task|null} The organization, and the task that subscribes it to its plan
     */
    public function create(AccountTest $account_test, Plan $base_plan, int $number, string $password = self::DEFAULT_PASSWORD): array
    {
        $test_name = 'test'.$number;
        $organization = new Organization;
        $organization->slug = $test_name;
        $organization->name = $test_name;
        $organization->description = $test_name;
        $organization->contact_first_name = 'Test';
        $organization->contact_last_name = (string) $number;
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
        $organization->account_test_id = $account_test->id;
        $organization->plan_id = $base_plan->id;
        $organization->save();

        // Create base domain
        OrganizationFacade::setOrganization($organization);

        AccountManager::accounts()->create($organization);
        $user = AccountManager::users()->add([
            'username' => $test_name,
            'first_name' => $test_name,
            'last_name' => $test_name,
            'name' => $test_name,
            'email' => $test_name.'@'.(Settings::get('base_domain') ?? 'example.com'),
            'password' => $password,
            'phone_number' => '1234567890',
        ]);
        $user->permissions()->addControlPanelAccess(organization: $organization);

        if ($user_model = $organization->users()->where('username', $test_name)->first()) {
            $organization->primary_contact()->associate($user_model);
            $organization->save();
        }

        $subscription = (new SubscriptionService($organization))->all();
        $subscription_task = ActionFacade::execute(new SubscriptionUpdate($organization, $subscription));

        return [$organization, $subscription_task];
    }
}
