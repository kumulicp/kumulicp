<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Organization;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());
    $this->organization = Organization::find(1);
    $this->other = Organization::factory()->create();

    $this->application = Application::factory()->create(['domain_option' => ['base']]);
    $this->version = AppVersion::factory()->create(['application_id' => $this->application->id, 'status' => 'active']);
    $this->plan = AppPlan::factory()->create(['application_id' => $this->application->id]);
});

function instanceIn(Organization $organization, array $attributes = []): AppInstance
{
    return AppInstance::factory()->create(array_merge([
        'application_id' => test()->application->id,
        'organization_id' => $organization->id,
        'version_id' => test()->version->id,
        'plan_id' => test()->plan->id,
        'status' => 'active',
    ], $attributes));
}

function editProps(AppInstance $app)
{
    return test()->get("/apps/{$app->id}/edit")->viewData('page')['props']['app'];
}

it('shows an app installed in another of the same organization as an addon to it', function () {
    $parent = instanceIn($this->organization);
    $child = instanceIn($this->organization, ['parent_id' => $parent->id]);

    expect(editProps($child)['parent_app'])->toMatchArray(['id' => $parent->id]);
});

it('does not call an app an addon to a shared app that is another organizations', function () {
    $shared = instanceIn($this->other);
    $child = instanceIn($this->organization, ['parent_id' => $shared->id]);

    expect(editProps($child)['parent_app'])->toBeNull();
});

it('shows the parent address for an app whose domain option is parent', function () {
    $this->application->domain_option = ['parent'];
    $this->application->save();
    $shared = instanceIn($this->other);
    $child = instanceIn($this->organization, ['parent_id' => $shared->id]);

    $props = editProps($child);

    expect($props['inherited_domain']['domain'])->toBe($shared->domain())
        ->and($props['parent_app'])->toBeNull();
});

it('leaves a multisite child its own domain instead of its parents', function () {
    $this->application->domain_option = ['base', 'subdomains'];
    $this->application->save();
    $shared = instanceIn($this->other);
    $child = instanceIn($this->organization, ['parent_id' => $shared->id]);

    expect(editProps($child)['inherited_domain'])->toBeNull();
});
