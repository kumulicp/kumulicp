<?php

namespace App\Http\Controllers\Account\Application;

use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Services\AppInstance\PendingAppChanges;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class Plans extends Controller
{
    public function index(AppInstance $app)
    {
        Gate::authorize('change-app-plan', [$app]);

        $plans = Subscription::app_instance($app)->allAvailable();
        $subsciption_count = $plans->count();

        if ($subsciption_count == 0) {
            return redirect('/apps');
        }

        return inertia('Organization/Apps/AppPlans', [
            'plans' => $plans->map(function ($plan) use ($app) {
                $stats = $plan->stats();

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'features' => [
                        'prices' => collect([
                            [
                                'name' => 'Base Price',
                                'description' => Arr::get($stats, 'base.price'),
                                'price' => Arr::get($stats, 'base.price'),
                            ],
                            [
                                'name' => 'Users',
                                'description' => Arr::get($stats, 'standard.calculation'),
                                'price' => Arr::get($stats, 'standard.price'),
                            ],
                            [
                                'name' => 'Volunteers',
                                'description' => Arr::get($stats, 'basic.calculation'),
                                'price' => Arr::get($stats, 'basic.price'),
                            ],
                            [
                                'name' => 'Additional Storage',
                                'description' => Arr::get($stats, 'storage.calculation'),
                                'price' => Arr::get($stats, 'storage.price'),
                            ],
                            [
                                'name' => 'Features',
                                'description' => implode(', ', $plan->availableFeatureNames()),
                                'price' => 1,
                            ],
                        ])->filter(function ($feature) {
                            return ! empty($feature['description']);
                        }),
                        'features' => $plan->features,
                    ],
                    'current' => $plan->id == $app->plan()->first()->id,
                    'url' => "/apps/{$app->id}/plans/{$plan->id}",
                    'order' => $plan->display_order,
                ];
            })->sortBy('order')->values()->all(),
            'app' => [
                'name' => $app->name,
                'label' => $app->label,
                'slug' => $app->application->slug,
                'id' => $app->id,
            ],
        ]);
    }

    public function show(AppInstance $app, AppPlan $plan)
    {
        Gate::authorize('update-to-plan', [$app, $plan]);

        $organization = Organization::account();
        $plans = Subscription::all();
        $dry_plan = $plans->dryAppChange($app, $plan);
        $stats = $dry_plan->compileCostStats();
        $prices = $dry_plan->compileAllStats();
        $total = $dry_plan->totalPrice();

        $customizations = Application::instance($app)->features()->setPlan($plan)->optional($app);

        return inertia('Organization/Apps/AppSubscriptionReview', [
            'app' => [
                'label' => $app->label,
                'name' => $app->name,
                'id' => $app->id,
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'features' => $plan->displayFeatures($organization->billingCurrency()),
                'payment_enabled' => $plan->payment_enabled,
            ],
            'customizations' => collect($customizations)->map(function ($customization) {
                return [
                    'name' => $customization['name'],
                    'label' => $customization['label'],
                    'description' => $customization['description'],
                ];
            }),
            'prices' => $prices,
            'total' => $total,
            'driver' => Billing::component(),
            'has_payment_method' => Billing::hasDefaultPaymentMethod(),
        ]);
    }

    public function update(Request $request, AppInstance $app, AppPlan $plan)
    {
        Gate::authorize('update-to-plan', [$app, $plan]);

        $validatedData = $request->validate([
            'customizations' => 'nullable',
        ]);

        $organization = Organization::account();
        $subscription = Subscription::all();
        $subscription->updateApp($plan, $app);

        $customizations = $request->input('customizations') ?? [];

        $application = Application::instance($app);
        $application->features()->update($customizations);

        // The plan is changed whatever state the app is in, but putting the change into the app (upgrade, update,
        // customizations) needs an app that answers: for one that doesn't it is written down and done once it is active
        $pending = app(PendingAppChanges::class);
        $deferred = $pending->defer($app, ['plan_change' => ['customizations' => $customizations]]);

        if (! $deferred) {
            $application->updateCustomizations($customizations);
        }

        // Billing and the rest of the subscription don't wait for the app
        $subscription_update = Action::execute(new SubscriptionUpdate($organization, $subscription));

        if ($deferred) {
            return redirect('/subscription/plans')->with('success', __('organization.plan.updated_waiting', ['app' => $app->label]));
        }

        $pending->queuePlanChange($application->get(), $customizations, $subscription_update);

        return redirect('/subscription/plans')->with('success', __('organization.plan.updated'));
    }
}
