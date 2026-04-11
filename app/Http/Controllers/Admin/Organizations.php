<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Http\Controllers\Controller;
use App\Log;
use App\Organization;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class Organizations extends Controller
{
    public function index()
    {
        $organization = auth()->user()->organization;
        $organizations = Organization::paginate(20);

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
                    'label' => 'Organizations',
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
            'subscription_stats' => $subscription->compileCostStats(),
            'breadcrumbs' => [
                [
                    'label' => 'Organizations',
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => 'Details',
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
                    'label' => 'Organizations',
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => 'Logs',
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
                    'label' => 'Organizations',
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => 'Tasks',
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

        return redirect('/admin/organizations/'.$organizationid)->with('success', 'Organization reactivated!');
    }

    public function deactivate($organizationid)
    {
        $organization = Organization::where('slug', $organizationid)->first();
        if ($organization) {
            $organization->deactivate();
        }

        return redirect('/admin/organizations/'.$organizationid)->with('success', 'Organization deactivated');
    }

    public function destroy(Organization $organization)
    {
        $this->authorize('delete-organization', $organization);

        Action::execute(new DeleteOrganization($organization));

        return redirect('/admin/organizations');
    }

    public function update_subscription(Request $request, Organization $organization)
    {
        $organization->discount_id = $request->input('discount_code');
        $organization->save();

        $subscription = (new SubscriptionService($organization))->all();
        Action::execute(new SubscriptionUpdate($organization, $subscription), background: true);

        return redirect("/admin/organizations/{$organization->id}")->with('success', 'Subscription is being updated');
    }
}
