<?php

namespace App\Http\Controllers\Account\Application;

use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\Actions\Apps\ProcessCustomizations;
use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Plans extends Controller
{
    public function index(AppInstance $app)
    {
        $this->authorize('change-app-plan', [$app]);

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
        $this->authorize('update-to-plan', [$app, $plan]);

        $organization = Organization::account();
        $plans = Subscription::all($organization);
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
                'features' => $plan->displayFeatures(),
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
        ]);
    }

    public function update(Request $request, AppInstance $app, AppPlan $plan)
    {
        $this->authorize('update-to-plan', [$app, $plan]);

        $validatedData = $request->validate([
            'customizations' => 'nullable',
        ]);

        $organization = Organization::account();
        $subscription = Subscription::all();
        $subscription->updateApp($plan, $app);

        $customizations = $request->input('customizations') ?? [];

        $application = Application::instance($app);
        $application->features()->update($customizations);
        $application->updateCustomizations($customizations);

        $subscription_update = Action::execute(new SubscriptionUpdate($organization, $subscription));

        $app_profile = Application::get($app->application->slug);

        if (Arr::get($app_profile, 'activation_type', 'chart') === 'chart') {
            Action::execute(new ApplicationUpgrade($application->get(), $application->get()->version), $subscription_update);
        }

        Action::execute(new ApplicationUpdate($application->get()), $subscription_update);

        Action::execute(new ProcessCustomizations($application->get(), $customizations), $subscription_update, delay: true);

        return redirect('/subscription/plans')->with('success', __('organization.plan.updated'));
    }
}
