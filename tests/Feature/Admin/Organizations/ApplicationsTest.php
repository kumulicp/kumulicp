<?php

namespace Tests\Feature\Admin\Organizations;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Organization;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ApplicationsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $support = new TestSupports;
        $support->seed();
        $support->activateDemoApp();

        return User::find(1);
    }

    public function test_breadcrumbs_link_back_to_the_shared_app_for_its_own_instance()
    {
        $admin = $this->adminUser();
        $demoApp = Application::where('slug', 'demo_app')->first();

        $sharedOrg = Organization::factory()->create(['type' => 'shared', 'slug' => 'shared']);
        $hiddenPlan = AppPlan::factory()->create([
            'name' => 'My Shared CRM',
            'application_id' => $demoApp->id,
            'hidden' => true,
        ]);

        $sharedAppInstance = new AppInstance;
        $sharedAppInstance->application_id = $demoApp->id;
        $sharedAppInstance->organization_id = $sharedOrg->id;
        $sharedAppInstance->version_id = 1;
        $sharedAppInstance->plan_id = $hiddenPlan->id;
        $sharedAppInstance->name = 'shared-crm';
        $sharedAppInstance->label = 'My Shared CRM';
        $sharedAppInstance->status = 'active';
        $sharedAppInstance->save();

        $response = $this->actingAs($admin)->get(
            "/admin/organizations/{$sharedOrg->id}/apps/{$sharedAppInstance->id}"
        );

        $response->assertInertia(fn ($page) => $page
            ->where('breadcrumbs.0.label', __('admin.shared_apps.shared_apps'))
            ->where('breadcrumbs.0.url', '/admin/service/shared-apps')
            ->where('breadcrumbs.1.label', $sharedAppInstance->label)
            ->where('breadcrumbs.1.url', '/admin/service/shared-apps/'.$sharedAppInstance->id)
        );
    }

    public function test_breadcrumbs_go_through_the_normal_org_apps_list_for_a_regular_app_instance()
    {
        $admin = $this->adminUser();
        $demoApp = Application::where('slug', 'demo_app')->first();
        $organization = Organization::factory()->create(['type' => 'business']);
        $plan = AppPlan::factory()->create([
            'application_id' => $demoApp->id,
            'hidden' => false,
        ]);

        $appInstance = new AppInstance;
        $appInstance->application_id = $demoApp->id;
        $appInstance->organization_id = $organization->id;
        $appInstance->version_id = 1;
        $appInstance->plan_id = $plan->id;
        $appInstance->name = 'demo-app-instance';
        $appInstance->label = 'Demo App Instance';
        $appInstance->status = 'active';
        $appInstance->save();

        $response = $this->actingAs($admin)->get(
            "/admin/organizations/{$organization->id}/apps/{$appInstance->id}"
        );

        $response->assertInertia(fn ($page) => $page
            ->where('breadcrumbs.0.label', __('admin.organizations.organizations'))
            ->where('breadcrumbs.1.label', $organization->name)
        );
    }
}
