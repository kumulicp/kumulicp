<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Organizations\DeactivateOrganization;
use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Enums\DeactivationReason;
use App\Http\Controllers\Controller;
use App\Log;
use App\Notifications\OrganizationDeletionScheduled;
use App\Organization;
use App\Plan;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Support\Facades\FastCache;
use App\Updates\PendingUpdates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Organizations extends Controller
{
    /**
     * How far ahead "delete now" is scheduled.
     */
    private const IMMEDIATE_DELETION_MINUTES = 2;

    public function index()
    {
        $organization = auth()->user()->organization;
        // The "shared" organization only exists to host shared app instances
        // -- it's not a real customer, so it's managed from the Shared Apps
        // page instead of showing up here. Test organizations aren't customers
        // either, and their pages are closed to admins.
        $organizations = Organization::where('type', '!=', 'shared')->notTest()->paginate(20);
        $out_of_date = app(PendingUpdates::class)->byOrganization($organizations->pluck('id')->all());

        return inertia()->render('Admin/Organizations/OrganizationsList', [
            'organizations' => $organizations->map(function ($organization) use ($out_of_date) {
                return [
                    'out_of_date' => $out_of_date[$organization->id] ?? 0,
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
        $out_of_date = app(PendingUpdates::class)->byInstance($organization->app_instances->pluck('id')->all());

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
                'deactivation_reason' => $organization->deactivation_reason,
                'deactivation_reason_note' => $organization->deactivation_reason_note,
                'deactivation_reason_text' => $organization->deactivationReasonText(),
            ],
            'apps' => $organization->app_instances->map(function ($app) use ($out_of_date) {
                return [
                    'id' => $app->id,
                    'updates' => $out_of_date[$app->id] ?? [],
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
            'deactivation_reasons' => collect(DeactivationReason::selectable())->map(fn (DeactivationReason $reason) => [
                'value' => $reason->value,
                'text' => $reason->label(),
            ])->all(),
            'default_deactivation_reason' => DeactivationReason::default()->value,
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
     *
     * "Now" is decided here rather than by the browser: an admin can set it
     * and walk away from the open dialog, and the clock has to start when they
     * confirm, with a few minutes of room to change their mind.
     */
    public function schedule_deletion(Request $request, Organization $organization)
    {
        // The browser sends an instant in UTC: "yesterday" leaves room for the
        // difference between the admin's day and the server's
        $validated = $request->validate([
            'now' => ['boolean'],
            'date' => ['required_without:now', 'nullable', 'date', 'after_or_equal:yesterday'],
            'reason' => ['required', Rule::enum(DeactivationReason::class)->only(DeactivationReason::selectable())],
            'reason_note' => ['required_if:reason,'.DeactivationReason::CUSTOM->value, 'nullable', 'string', 'max:500'],
        ]);

        $timezone = config('app.timezone');
        $immediate = (bool) ($validated['now'] ?? false);

        $deletion_at = $immediate
            ? now()->addMinutes(self::IMMEDIATE_DELETION_MINUTES)
            : Carbon::parse($validated['date'])->setTimezone($timezone);

        // $deletion_at is in the app's timezone, which is what the sweep
        // compares against, and the datetime cast stores it as it is, so no
        // timezone conversion can shift it
        $organization->deletion_scheduled_at = $deletion_at;
        $organization->deactivation_reason = $validated['reason'];
        // The note only goes with a custom reason
        $organization->deactivation_reason_note = $validated['reason'] === DeactivationReason::CUSTOM->value
            ? trim($validated['reason_note'])
            : null;
        $organization->save();

        if ($organization->status !== 'deactivated') {
            Action::execute(new DeactivateOrganization($organization));
        }

        $message = $immediate
            ? __('admin.organizations.deletion_scheduled_in_minutes', ['minutes' => self::IMMEDIATE_DELETION_MINUTES])
            : __('admin.organizations.deletion_scheduled', ['date' => $deletion_at->format('M d, Y')]);

        return redirect("/admin/organizations/{$organization->id}")->with('success', $message);
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
