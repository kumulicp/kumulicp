<?php

use App\Organization;
use App\Plan;
use App\Services\SubscriptionService;
use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates the demo app and acts as the demo user.

// A real request gets fresh scoped services (Octane resets them) and a freshly loaded user and
// organization. The test container keeps both, which would hide (or invent) stale plan data.
function newRequest($test): void
{
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    $test->actingAs(User::findOrFail($test->user->id));
}

function menuUrls($test): array
{
    newRequest($test);
    $menu = $test->get('/')->viewData('page')['props']['menu'];

    return collect($menu)->flatMap(fn ($item) => array_merge([$item['url']], collect($item['submenu'] ?? [])->pluck('url')->all()))->all();
}

function domainsPlan(Organization $organization, bool $enabled): Plan
{
    return Plan::factory()->create([
        'org_type' => $organization->type,
        'type' => 'package',
        'payment_enabled' => false,
        'domain_enabled' => $enabled,
        'app_plans' => ['demo_app' => ['max' => 5, 'plans' => []]],
    ]);
}

beforeEach(function () {
    // Production queues the SubscriptionUpdate task for a worker, which is what used to clear the cache
    Queue::fake();
    $this->organization = Organization::find(1);
    $this->organization->domains()->where('type', '!=', 'base')->delete();
});

it('shows the domains menu item as soon as the user subscribes to a plan with domains', function () {
    $without = domainsPlan($this->organization, false);
    $with = domainsPlan($this->organization, true);

    newRequest($this);
    $this->post("/subscription/{$this->organization->id}/plans/{$without->id}")->assertRedirect();
    expect(menuUrls($this))->not->toContain('/settings/domains');

    newRequest($this);
    $this->post("/subscription/{$this->organization->id}/plans/{$with->id}")->assertRedirect();
    expect(menuUrls($this))->toContain('/settings/domains');

    newRequest($this);
    $this->post("/subscription/{$this->organization->id}/plans/{$without->id}")->assertRedirect();
    expect(menuUrls($this))->not->toContain('/settings/domains');
});

it('shows and hides menu items when an admin edits the plan the organization is on', function () {
    $plan = domainsPlan($this->organization, false);
    $this->organization->forceFill(['plan_id' => $plan->id])->save();

    expect(menuUrls($this))->not->toContain('/settings/domains');

    $update = fn (bool $domains) => $this->post("/admin/service/plans/{$plan->id}", [
        'name' => $plan->name,
        'description' => 'Plan',
        'type' => 'package',
        'org_type' => $plan->org_type,
        'domain_enabled' => $domains,
        'email_enabled' => false,
    ]);

    newRequest($this);
    $update(true)->assertRedirect();
    expect(menuUrls($this))->toContain('/settings/domains');

    newRequest($this);
    $update(false)->assertRedirect();
    expect(menuUrls($this))->not->toContain('/settings/domains');
});

it('shows the new plan\'s menu items when an admin moves the organization to another plan', function () {
    $without = domainsPlan($this->organization, false);
    $with = domainsPlan($this->organization, true);
    $this->organization->forceFill(['plan_id' => $without->id])->save();

    expect(menuUrls($this))->not->toContain('/settings/domains');

    newRequest($this);
    $this->post("/admin/organizations/{$this->organization->id}/change_plan", ['plan_id' => $with->id])->assertSessionHasNoErrors();

    expect(menuUrls($this))->toContain('/settings/domains');
});

it('drops the cached menu when the app plan of an instance changes', function () {
    $slug = $this->organization->slug;
    Cache::tags($slug)->put('org_menu:en', ['stale'], null);

    (new SubscriptionService($this->organization))->all()->updateApp($this->support->demo_app_2, $this->demoApp);

    expect(Cache::tags($slug)->has('org_menu:en'))->toBeFalse();
});
