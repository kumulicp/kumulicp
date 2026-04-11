<?php

namespace App\Http\Controllers\Account;

use App\Actions\Organizations\SubscriptionUpdate;
use App\Application;
use App\Http\Controllers\Controller;
use App\Notifications\SubscriptionCancelled;
use App\Plan;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization;
use App\Support\Facades\Settings;
use App\Support\Facades\Subscription as SubscriptionFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class Subscription extends Controller
{
    public function index()
    {
        $this->authorize('has-billing-account');
        $organization = Organization::account();
        if ($organization->suborganizations()->count() === 0) {
            return $this->commonView($organization);
        }

        $subscription = (new SubscriptionService($organization))->all();

        $suborganizations = [];

        foreach ($organization->suborganizations as $suborganization) {
            $suborg_subscription = (new SubscriptionService($suborganization))->all();
            $suborganizations[] = [
                'id' => $suborganization->id,
                'name' => $suborganization->name,
                'billing_type' => $suborganization->setting('include_in_parent_invoice', true) ? "With {$organization->name}" : 'Separate',
                'total' => $suborg_subscription->totalPrice(),
            ];
        }

        return inertia('Organization/Subscription/SubscriptionOverview', [
            'organizations' => collect(([[
                'id' => $organization->id,
                'name' => $organization->name,
                'total' => $subscription->totalPrice(),
            ]]))->merge($suborganizations),
            'invoices' => Billing::invoices(),
            'upcoming_invoice' => Billing::upcomingInvoice(),
            'breadcrumbs' => [
                [
                    'label' => 'Subscription Summary',
                ],
            ],
        ]);
    }

    public function show(\App\Organization $organization)
    {
        return $this->commonView($organization);
    }

    public function options(?\App\Organization $organization = null)
    {
        if (! $organization) {
            return redirect('/subscription/'.auth()->user()->organization_id.'/options');
        }

        if ($organization->setting('step') === 1) {
            return redirect('/discover');
        }

        $plans = (new SubscriptionService($organization))->base()->allAvailable();

        return inertia('Organization/Subscription/SubscriptionOptions', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'plans' => $plans->map(function ($plan) use ($organization) {
                $apps = [];
                foreach ($plan->app_plans as $app_name => $app_plan) {
                    if (Arr::get($app_plan, 'max', 0) > 0) {
                        $apps[] = $app_name;
                    }
                }

                $apps = Application::whereIn('name', $apps)->get();
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
                                'name' => 'Emails',
                                'description' => Arr::get($stats, 'email.calculation'),
                                'price' => Arr::get($stats, 'email.price'),
                            ],
                        ])->filter(function ($feature) {
                            return ! empty($feature['description']) && ! empty($feature['price']);
                        }),
                        'features' => $plan->features,
                    ],
                    'current' => $plan->id === $organization->plan_id,
                    'url' => "/subscription/{$organization->id}/plans/{$plan->id}",
                ];
            })->sortBy('order')->values()->all(),
            'breadcrumbs' => [
                [
                    'label' => 'Subscription Overview',
                    'url' => '/subscription',
                ],
                [
                    'label' => 'Plans',
                    'url' => '/subscription/plans',
                ],
                [
                    'label' => 'Available Plans',
                ],
            ],
        ]);
    }

    public function plans()
    {
        $organization = auth()->user()->organization;

        $suborgs = collect();
        foreach ($organization->suborganizations as $suborganization) {
            $suborgs->push([
                'info' => $suborganization,
                'subscription' => new SubscriptionService($suborganization),
            ]);
        }

        $subscription = SubscriptionFacade::all();

        $base_plan = $subscription->base();
        $app_instance_plans = $subscription->appInstancePlans();

        $prices = $subscription->compileCostStats();

        return inertia('Organization/Subscription/SubscriptionPlans', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'plans' => collect([[
                'id' => $base_plan->id,
                'name' => $base_plan->name,
                'status' => implode(' ', [__('labels.'.$base_plan->status()), $organization->deactivate_at?->format('M d, Y')]),
                'type' => 'base',
                'entity' => [
                    'name' => __('organization.subscription.base_plan'),
                ],
                'plans_url' => "/subscription/{$organization->id}/options",
                'can' => [
                    'change_plan' => count($base_plan->allAvailable()) > 1,
                    'unsubscribe' => Gate::allows('unsubscribe', $organization),
                    'resubscribe' => Gate::allows('resubscribe', $organization),
                ],
            ]])->merge($app_instance_plans->map(function ($plan) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'status' => implode(' ', [__('labels.'.$plan->status()), $plan->app_instance->deactivate_at?->format('M d, Y')]),
                    'type' => 'app',
                    'entity' => [
                        'name' => $plan->app_instance->label,
                        'id' => $plan->app_instance->id,
                    ],
                    'plans_url' => "/apps/{$plan->app_instance->id}/plans",
                    'can' => [
                        'change_plan' => Gate::allows('change-app-plan', $plan->app_instance),
                        'unsubscribe' => Gate::allows('deactivate-app', $plan->app_instance),
                        'resubscribe' => Gate::allows('reactivate-app', $plan->app_instance),
                    ],
                ];
            })),
            'suborgs' => $suborgs->map(function ($suborg) {
                $base_plan = $suborg['subscription']->base();
                $suborg_info = $suborg['info'];

                return [
                    'id' => $suborg_info->id,
                    'name' => $suborg_info->name,
                    'plans' => collect([[
                        'id' => $base_plan->id,
                        'name' => $base_plan->name,
                        'status' => implode(' ', [__('labels.'.$base_plan->status()), $suborg_info->deactivate_at?->format('M d, Y')]),
                        'type' => 'base',
                        'entity' => [
                            'name' => __('organization.subscription.base_plan'),
                        ],
                        'plans_url' => "/subscription/{$suborg_info->id}/options",
                        'can' => [
                            'change_plan' => count($base_plan->allAvailable()) > 1,
                            'unsubscribe' => Gate::allows('unsubscribe', $suborg_info),
                            'resubscribe' => Gate::allows('resubscribe', $suborg_info),
                        ],
                    ]])->merge($suborg['subscription']->appInstancePlans()->map(function ($plan) {
                        return [
                            'id' => $plan->id,
                            'name' => $plan->name,
                            'status' => implode(' ', [__('labels.'.$plan->status()), $plan->app_instance->deactivate_at?->format('M d, Y')]),
                            'type' => 'app',
                            'entity' => [
                                'name' => $plan->app_instance->label,
                                'id' => $plan->app_instance->id,
                            ],
                            'plans_url' => "/apps/{$plan->app_instance->id}/plans",
                            'can' => [
                                'change_plan' => Gate::allows('change-app-plan', $plan->app_instance),
                                'unsubscribe' => Gate::allows('deactivate-app', $plan->app_instance),
                                'resubscribe' => Gate::allows('reactivate-app', $plan->app_instance),
                            ],
                        ];
                    })),
                ];
            }),
            'prices' => $prices,
        ]);
    }

    public function review(\App\Organization $organization, Plan $plan)
    {
        $this->authorize('select-plan', [$organization, $plan]);
        $plans = SubscriptionFacade::all();
        $base_plan = SubscriptionFacade::dryBaseChange($plan)->base();
        $prices = $plans->compileAllStats();

        return inertia('Organization/Subscription/SubscriptionReview', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'type' => $plan->type,
                'payment_enabled' => $plan->payment_enabled,
                'features' => [
                    'prices' => collect([
                        [
                            'name' => 'Base Price',
                            'description' => $base_plan->optionStats('base')['price'],
                            'price' => $base_plan->optionStats('base')['price'],
                        ],
                        [
                            'name' => 'Users',
                            'description' => $base_plan->optionStats('standard')['calculation'],
                            'price' => $base_plan->optionStats('standard')['price'],
                        ],
                        [
                            'name' => 'Volunteers',
                            'description' => $base_plan->optionStats('basic')['calculation'],
                            'price' => $base_plan->optionStats('basic')['price'],
                        ],
                        [
                            'name' => 'Additional Storage',
                            'description' => $base_plan->optionStats('storage')['calculation'],
                            'price' => $base_plan->optionStats('storage')['price'],
                        ],
                        [
                            'name' => 'Emails',
                            'description' => $base_plan->optionStats('email')['calculation'],
                            'price' => $base_plan->optionStats('email')['price'],
                        ],
                    ])->filter(function ($feature) {
                        return ! empty($feature['description']) && ! empty($feature['price']);
                    }),
                    'features' => $plan->features,
                ],
            ],
            'prices' => $prices,
            'total' => SubscriptionFacade::totalPrice(),
            'breadcrumbs' => [
                [
                    'label' => 'Subscription Overview',
                    'url' => '/subscription',
                ],
                [
                    'label' => 'Plans',
                    'url' => '/subscription/plans',
                ],
                [
                    'label' => 'Available Plans',
                    'url' => "/subscription/{$organization->id}/options",
                ],
                [
                    'label' => 'Review '.$plan->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, \App\Organization $organization, Plan $plan)
    {
        $this->authorize('select-plan', [$organization, $plan]);

        // Check if needs to set payment
        if ($plan->payment_enabled && ! Billing::hasDefaultPaymentMethod()) {
            return redirect('/subscription/'.$plan->id.'/payment');
        }

        $organization_subscription = (new SubscriptionService($organization))->all();
        $organization_subscription->updateBase($plan);

        if ($organization->status === 'new' && ! $organization->setting('step')) {
            $organization->updateSetting('step', 1);
        }
        $organization->status = 'active';
        $organization->save();

        Action::execute(new SubscriptionUpdate($organization, $organization_subscription));

        return redirect("/subscription/{$organization->id}/options")->with('success', __('organization.subscription.updated'))->with('reset_menu', true)->with('reset_step', true);
    }

    public function cancel(Request $request, \App\Organization $organization)
    {
        if (Billing::isBillable()) {
            $ends_at = Billing::periodEnds();
        } else {
            $ends_at = now();
        }

        $organization->deactivate_at = $ends_at;
        $organization->status = 'deactivating';
        $organization->save();

        foreach ($organization->app_instances as $app) {
            $app->status = 'deactivating';
            $app->deactivate_at = $ends_at;
            $app->save();
        }

        $subscription = SubscriptionFacade::all();

        Action::execute(new SubscriptionUpdate($organization, $subscription), background: true);
        $organization->notifyAdmins(new SubscriptionCancelled($organization));

        foreach ($organization->suborganizations as $child) {
            $child->deactivate_at = $ends_at;
            $child->status = 'deactivating';
            $child->save();

            $billing = new Billing($child);

            if ($billing->isBillable()) {
                $billing->cancel();
                $child->notifyAdmins(new SubscriptionCancelled($child));
            }
        }

        return back()->with('success', __('organization.subscription.cancelled'));
    }

    public function resubscribe()
    {
        $organization = auth()->user()->organization;

        $organization->status = 'active';
        $organization->save();

        foreach ($organization->app_instances()->where('status', '!=', 'deactivated')->get() as $app) {
            $app->status = 'active';
            $app->deactivate_at = null;
            $app->save();
        }

        $subscription = SubscriptionFacade::all();

        Action::execute(new SubscriptionUpdate($organization, $subscription), background: true);

        foreach ($organization->suborganizations()->where('status', '!=', 'deactivated')->get() as $child) {
            $child->deactivate_at = null;
            $child->status = 'active';
            $child->save();
            $child_subscription = (new SubscriptionService($child))->all();

            if (Billing::organization($child)->isBillable()) {
                Action::execute(new SubscriptionUpdate($child, $child_subscription), background: true);
            }
        }

        return back()->with('success', __('organization.plan.resubscribed'));
    }

    public function api(Request $request)
    {
        $organization = auth()->user()->organization;

        $data = [];

        if ($plan = $organization->plan) {
            $data = array_merge($data, [
                'name' => $plan->name,
                'features' => json_decode($plan->features, true),
            ]);
        }

        foreach ($organization->active_apps() as $app) {
            $plan = $app->subscription;

            $data = array_merge($data, [
                'name' => $plan->name,
                'features' => json_decode($plan->features, true),
            ]);
        }

        return response()->json($data);
    }

    public function download($invoice)
    {
        $organization = auth()->user()->organization;

        return $organization->downloadInvoice($invoice, [
            'vendor' => Settings::get('invoice_vendor_name'),
            'product' => Settings::get('invoice_vendor_product'),
            'street' => Settings::get('invoice_vendor_street'),
            'location' => Settings::get('invoice_vendor_location'),
            'phone' => Settings::get('invoice_vendor_phone_number'),
            'email' => Settings::get('invoice_vendor_email'),
            'url' => Settings::get('invoice_vendor_url'),
            'vendorVat' => Settings::get('invoice_vendor_vat'),
        ], 'my-invoice');
    }

    private function commonView(\App\Organization $organization)
    {
        $this->authorize('view-organization', $organization);
        $subscription = (new SubscriptionService($organization))->all();

        $stats = $subscription->compileCostStats();

        return inertia('Organization/Subscription/SubscriptionView', [
            'stats' => $stats,
            'discount' => Billing::discount(),
            'invoices' => Billing::invoices(),
            'upcoming_invoice' => Billing::upcomingInvoice(),
            'breadcrumbs' => [
                [
                    'url' => '/subscription',
                    'label' => 'Subscription Summary',
                ],
                [
                    'label' => $organization->name,
                ],
            ],
        ]);
    }
}
