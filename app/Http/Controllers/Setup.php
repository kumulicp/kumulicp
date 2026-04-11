<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\SubscriptionUpdate;
use App\Plan;
use App\ServerSetting;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use App\Support\Facades\Settings;
use App\Support\Facades\Subscription;
use App\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Stripe\Customer;
use Stripe\Stripe;

class Setup extends Controller
{
    public function settings()
    {
        // Leave here also as a database connection check
        $installed = ServerSetting::where('key', 'installed')->first();
        if ($installed && ($installed->value == 1)) { // Don't use === because it could be a string
            return redirect('/');
        }

        // Run account manager connection check
        $checks['AccountManager check: '.AccountManager::driver()] = AccountManager::testConnection();

        // Run system checks
        $disabled = explode(',', ini_get('disable_functions'));

        if (env('ACCOUNTMANAGER_DRIVER') === 'ldap' || env('LOGIN_PROVIDER') === 'ldap') {
            $checks[__('setup.extension', ['extension' => 'ldap'])] = extension_loaded('ldap');
        }
        $checks[__('setup.extension', ['extension' => 'intl'])] = extension_loaded('intl');
        $checks[__('setup.extension', ['extension' => 'zip'])] = extension_loaded('zip');
        $checks[__('setup.extension', ['extension' => 'mysqli'])] = extension_loaded('mysqli');
        $checks[__('setup.extension', ['extension' => 'curl'])] = extension_loaded('curl');
        $checks[__('setup.function', ['function' => 'curl_exec'])] = ! in_array('curl_exec', $disabled);

        if (config('billing.default') === 'stripe' && ! is_null(env('STRIPE_KEY')) && ! is_null(env('STRIPE_SECRET'))) {
            try {
                Stripe::setApiKey(env('STRIPE_SECRET'));
                // Attempt to retrieve a list of test customers (will be empty if new)
                $customers = Customer::all(['limit' => 1]);

                $checks[__('setup.stripe_connection')] = true;
            } catch (\Exception $e) {
                $checks[__('setup.stripe_connection')] = false;
            }
        }

        foreach ($checks as $check) {
            if (! $check) {
                return inertia('Setup/SetupChecks', [
                    'checks' => $checks,
                ]);
            }
        }

        return inertia('Setup/SetupSettings');
    }

    public function build(Request $request)
    {

        if (Settings::get('installed', 0) == 1) {
            return redirect('/');
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:100', 'lowercase'],
            'contact_email' => ['required', 'string', 'max:100', 'unique:users,email', 'lowercase'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'slug' => ['required', 'string', 'max:30', 'alpha_dash', 'lowercase'],
            'contact_first_name' => ['required', 'string', 'max:100'],
            'contact_last_name' => ['required', 'string', 'max:100'],
        ]);

        // Create Subscription with unlimited access
        $plan = new Plan;
        $plan->name = __('setup.plan.name');
        $plan->description = __('setup.plan.description');
        $plan->app_plans = json_decode('{"nextcloud": {"max": "1", "plans": "enabled"}, "wordpress": {"max": "1", "plans": "enabled"}}', true);
        $plan->settings = json_decode('{"base": {"price": null, "storage": null, "price_id": null}, "basic": {"max": null, "name": null, "price": null, "amount": null, "storage": null, "price_id": null}, "email": {"max": null, "price": null, "storage": null, "price_id": null}, "storage": {"max": null, "price": null, "amount": null, "price_id": null}, "standard": {"max": null, "price": null, "storage": null, "price_id": null}, "application": {"max": null, "price": null, "price_id": null}, "suborganizations": {"enabled": false}}', true);
        $plan->archive = 1;
        $plan->save();

        // Create Organization Account
        $organization = new \App\Organization;
        $organization->slug = Str::snake($data['slug']);
        $organization->name = __('setup.first_org');
        $organization->api_token = Str::random(60);
        $organization->contact_first_name = $data['contact_first_name'];
        $organization->contact_last_name = $data['contact_last_name'];
        $organization->contact_email = $data['contact_email'];
        $organization->secretpw = Str::password(20, true, true, false, false);
        $organization->status = 'active';
        $organization->type = 'superaccount';
        $organization->plan_id = $plan->id;
        $organization->settings = ['step' => 4];
        $organization->save();
        Organization::setOrganization($organization);

        try {
            AccountManager::initiate();
            AccountManager::accounts()->create($organization);
            $user = AccountManager::users()->add([
                'username' => $data['username'],
                'password' => $data['password'],
                'first_name' => $data['contact_first_name'],
                'last_name' => $data['contact_last_name'],
                'email' => $data['contact_email'],
                'is_allowed' => true,
            ]);
            $user->permissions()->addControlPanelAccess(verified: true);
            $user->permissions()->addControlPanelAdminAccess();
        } catch (\Throwable $e) {
            report($e);
            $organization->delete();
            $plan->delete();
            foreach (ServerSetting::all() as $setting) {
                $setting->delete();
            }
            throw new \Exception($e->getMessage());

            return back()->withError($e->getMessage());
        }

        $organization->save();

        $subscription = Subscription::all();
        // This task will confirm cronjob running and setup domain
        $task_subscription = Action::execute(new SubscriptionUpdate($organization, $subscription), background: true);

        // Set server as installed
        Settings::update('installed', 1);

        return redirect('/')->with('success', __('setup.complete'));
    }
}
