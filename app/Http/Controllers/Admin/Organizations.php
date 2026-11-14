<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Organizations\DeactivateOrganization;
use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Http\Controllers\Controller;
use App\Log;
use App\Notifications\OrganizationDeletionScheduled;
use App\Organization;
use App\Plan;
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
                'deletion_scheduled_at' => $organization->deletion_scheduled_at?->toIso8601String(),
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
     * The admin override: an organization can be moved to any plan at all --
     * hidden and archived plans, plans of other organization types, and plans
     * whose limits the organization has outgrown. The only things refused are
     * a deactivated organization and a plan it is already on.
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

    /**
     * Deactivate the organization now and set the date it is permanently
     * deleted, with all of its apps, settings and data.
     */
    public function schedule_deletion(Request $request, Organization $organization)
    {
        // The browser sends an instant in UTC: "yesterday" leaves room for the
        // difference between the admin's day and the server's
        $validated = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:yesterday'],
        ]);

        $organization->deletion_scheduled_at = Carbon::parse($validated['date']);
        $organization->save();

        if ($organization->status !== 'deactivated') {
            Action::execute(new DeactivateOrganization($organization));
        }

        return redirect("/admin/organizations/{$organization->id}")->with('success', __('admin.organizations.deletion_scheduled', ['date' => $organization->deletion_scheduled_at->format('M d, Y')]));
    }

    public function cancel_deletion(Organization $organization)
    {
        $organization->deletion_scheduled_at = null;
        $organization->save();

        return redirect("/admin/organizations/{$organization->id}")->with('success', __('admin.organizations.deletion_cancelled'));
    }

    public function notify_deletion(Organization $organization)
    {
        if (! $organization->deletion_scheduled_at) {
            throw ValidationException::withMessages(['date' => __('admin.organizations.deletion_not_scheduled')]);
        }

        $organization->notifyAdmins(new OrganizationDeletionScheduled($organization));

        return redirect("/admin/organizations/{$organization->id}")->with('success', __('admin.organizations.deletion_notified'));
    }

    private function selectablePlans(Organization $organization): array
    {
        return Plan::orderBy('archive')
            ->orderBy('org_type')
            ->orderBy('display_order')
            ->get()
            ->map(function (Plan $plan) use ($organization) {
                $current = $plan->id === $organization->plan_id;
                $blocked = $current ? null : $this->planChangeBlockedReason($organization, $plan);

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'org_type' => $plan->org_type,
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

        if ($organization->plan_id === $plan->id) {
            return __('organization.plan.denied.subscribed');
        }

        return null;
    }
}
