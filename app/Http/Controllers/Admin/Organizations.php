<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Http\Controllers\Controller;
use App\Log;
use App\Organization;
use App\Plan;
use App\Services\Organization\BasePlanService;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Support\Facades\FastCache;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Organizations extends Controller
{
    public function index()
    {
        $organization = auth()->user()->organization;
        // The "shared" organization only exists to host shared app instances
        // -- it's not a real customer, so it's managed from the Shared Apps
        // page instead of showing up here. Test organizations aren't customers
        // either, and their pages are closed to admins.
        $organizations = Organization::where('type', '!=', 'shared')->notTest()->paginate(20);

        return inertia()->render('Admin/Organizations/OrganizationsList', [
            'organizations' => $organizations->map(function ($organization) {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'is_suborg' => is_int($organization->parent_organization_id),
                    'contact_name' => $organization->contact_first_name.' '.$organization->contact_last_name,
                    'contact_email' => $organization->contact_email,
                    'status' => $organization->status,
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.organizations.organizations'),
                ],
            ],
        ]);
    }

    public function show(Organization $organization)
    {
        $subscription = (new SubscriptionService($organization))->all();

        return inertia()->render('Admin/Organizations/OrganizationView', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'description' => $organization->description,
                'slug' => $organization->slug,
                'street' => $organization->street,
                'city' => $organization->city,
                'state' => $organization->state,
                'country' => $organization->country,
                'contact_name' => $organization->contact_first_name.' '.$organization->contact_last_name,
                'contact_email' => $organization->contact_email,
                'contact_phone_number' => $organization->contact_phone_number,
                'status' => $organization->status,
            ],
            'apps' => $organization->app_instances->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->application->name,
                    'version' => $app->version->name,
                    'status' => $app->status,
                    'plan' => [
                        'id' => $app->plan->id,
                        'name' => $app->plan->name,
                    ],
                ];
            }),
            'base_plan' => [
                'id' => $organization->plan->id,
                'name' => $organization->plan->name,
                'description' => $organization->plan->description,
                'discount_id' => $organization->discount_id,
            ],
            'available_plans' => $this->selectablePlans($organization),
            'subscription_stats' => $subscription->compileCostStats(),
            'breadcrumbs' => [
                [
                    'label' => __('admin.organizations.organizations'),
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => __('admin.organizations.details'),
                ],
            ],
        ]);
    }

    public function logs(Organization $organization)
    {
        $logs = Log::where('organization_id', $organization->id)->orderBy('created_at', 'desc')->paginate(30);

        return inertia()->render('Admin/Organizations/OrganizationLogs', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'logs' => $logs->map(function ($log) {
                return [
                    'level' => $log->level_name,
                    'message' => $log->message,
                    'time' => (new Carbon($log->created_at))->format('Y-m-d h:m'),
                ];
            }),
            'meta' => [
                'total' => $logs->total(),
                'pages' => $logs->lastPage(),
                'page' => $logs->currentPage(),
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.organizations.organizations'),
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => __('admin.logs.logs'),
                ],
            ],
        ]);
    }

    public function tasks(Organization $organization)
    {
        return inertia()->render('Admin/Organizations/OrganizationTasks', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'apps' => $organization->app_instances->map(function ($app) {
                    $text = $app->application->name.' ('.$app->domain().')';

                    return [
                        'value' => $app->id,
                        'text' => $text,
                    ];
                }),
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.organizations.organizations'),
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => __('admin.tasks.tasks'),
                ],
            ],
        ]);
    }

    public function reactivate($organizationid)
    {
        $organization = Organization::where('slug', $organizationid)->first();
        if ($organization) {
            $organization->reactivate();
        }

        return redirect('/admin/organizations/'.$organizationid)->with('success', __('admin.organizations.reactivated'));
    }

    public function deactivate($organizationid)
    {
        $organization = Organization::where('slug', $organizationid)->first();
        if ($organization) {
            $organization->deactivate();
        }

        return redirect('/admin/organizations/'.$organizationid)->with('success', __('admin.organizations.deactivated'));
    }

    public function destroy(Organization $organization)
    {
        Gate::authorize('delete-organization', $organization);

        Action::execute(new DeleteOrganization($organization));

        return redirect('/admin/organizations');
    }

    public function update_subscription(Request $request, Organization $organization)
    {
        $organization->discount_id = $request->input('discount_code');
        $organization->save();

        $subscription = (new SubscriptionService($organization))->all();
        Action::execute(new SubscriptionUpdate($organization, $subscription), background: true);

        return redirect("/admin/organizations/{$organization->id}")->with('success', __('admin.organizations.subscription_updating'));
    }

    /**
     * Admins can move an organization to any plan of its type -- including
     * hidden and archived plans that customers can't pick for themselves.
     * Unlike the customer flow, this isn't limited to the plans an
     * organization has available, but a plan the organization has outgrown
     * is still refused so its limits can't be left broken.
     */
    public function change_plan(Request $request, Organization $organization)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        if ($reason = $this->planChangeBlockedReason($organization, $plan)) {
            throw ValidationException::withMessages(['plan_id' => $reason]);
        }

        $subscription = (new SubscriptionService($organization))->all();
        $subscription->updateBase($plan);

        FastCache::clear(organization: $organization);

        Action::execute(new SubscriptionUpdate($organization, $subscription));

        return redirect("/admin/organizations/{$organization->id}")->with('success', __('admin.organizations.plan_changed', ['plan' => $plan->name]));
    }

    private function selectablePlans(Organization $organization): array
    {
        return Plan::where('org_type', $organization->type)
            ->orderBy('archive')
            ->orderBy('display_order')
            ->get()
            ->map(function (Plan $plan) use ($organization) {
                $current = $plan->id === $organization->plan_id;
                $blocked = $current ? null : $this->planChangeBlockedReason($organization, $plan);

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'visibility' => match (true) {
                        (bool) $plan->archive => 'archived',
                        $plan->status === 'available' => 'public',
                        default => 'hidden',
                    },
                    'current' => $current,
                    'disabled_reason' => $blocked,
                ];
            })
            ->values()
            ->all();
    }

    private function planChangeBlockedReason(Organization $organization, Plan $plan): ?string
    {
        if ($organization->status === 'deactivated') {
            return __('organization.plan.denied.unavailable');
        }

        if ($plan->org_type !== $organization->type) {
            return __('organization.plan.denied.unavailable');
        }

        if ($organization->plan_id === $plan->id) {
            return __('organization.plan.denied.subscribed');
        }

        // BasePlanService::isAnyMaxBroken() is memoized per account, not per
        // plan, so check the limits directly rather than reuse a stale answer
        $base_plan = new BasePlanService($organization, $plan);
        $broken = $base_plan->isMaxAppsBroken();
        foreach ($base_plan->pricing_options as $entity) {
            $broken = $broken || $base_plan->isMaxBroken($entity);
        }

        return $broken ? __('organization.plan.denied.limit') : null;
    }
}
