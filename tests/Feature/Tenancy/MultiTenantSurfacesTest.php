<?php

use App\Announcement;
use App\Organization;
use App\Support\Facades\Menu;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The seeded demo organization is a superaccount and the demo user is an admin.

beforeEach(function () {
    (new TestSupports)->seed();
});

function tenancyMenuUrls(string $menu): array
{
    return collect(Menu::build($menu))
        ->filter(fn ($item) => $item['perm'])
        ->flatMap(fn ($item) => array_merge(
            [$item['url']],
            collect($item['submenu'] ?? [])->filter(fn ($sub) => $sub['perm'])->pluck('url')->all(),
        ))
        ->all();
}

dataset('multi_tenant_pages', [
    'subscription summary' => '/subscription',
    'subscription plans' => '/subscription/plans',
    'subscription options' => '/subscription/options',
    'payment method' => '/subscription/payment/method',
    'service plans' => '/admin/service/plans',
    'invoice settings' => '/admin/settings/invoice',
    'registration settings' => '/admin/settings/registration',
    'announcements' => '/admin/service/announcements',
]);

it('hides the pages only a hosting provider uses in single mode', function (string $url) {
    useSingleTenancy();

    $this->actingAs(User::where('username', 'demo')->firstOrFail())
        ->get($url)
        ->assertNotFound();
})->with('multi_tenant_pages');

it('keeps those pages in multi-tenant mode', function (string $url) {
    $response = $this->actingAs(User::where('username', 'demo')->firstOrFail())->get($url);

    expect($response->status())->not->toBe(404);
})->with('multi_tenant_pages');

it('does not let anyone sign up for a single-organization install', function () {
    useSingleTenancy();

    $this->get('/register')->assertNotFound();
    $this->get('/public/org/demo/register')->assertNotFound();
});

it('still lets people sign up in multi-tenant mode', function () {
    $this->get('/register')->assertOk();
});

it('closes the subscription and lifecycle actions of the organization in single mode', function () {
    useSingleTenancy();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    $this->post('/admin/organizations/1/schedule_deletion', ['now' => true, 'reason' => 'custom', 'reason_note' => 'x'])->assertNotFound();
    $this->post('/admin/organizations/1/change_plan', ['plan_id' => 1])->assertNotFound();
    $this->post('/admin/organizations/1/update_subscription')->assertNotFound();
    $this->get('/admin/organizations/1/deactivate')->assertNotFound();
    $this->get('/admin/organizations/1/remove')->assertNotFound();

    expect(Organization::find(1)->status)->not->toBe('deactivated');
});

it('goes straight to the one organization instead of listing organizations', function () {
    useSingleTenancy();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    $this->get('/admin/organizations')->assertRedirect('/admin/organizations/1');
    $this->get('/admin/organizations/1')->assertOk();
});

it('lists organizations in multi-tenant mode', function () {
    $this->actingAs(User::where('username', 'demo')->firstOrFail())
        ->get('/admin/organizations')
        ->assertOk();
});

it('takes the multi-tenant pages out of the admin menu in single mode', function () {
    useSingleTenancy();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    $urls = tenancyMenuUrls('admin');

    expect($urls)->toContain('/admin/organizations/1');
    expect($urls)->not->toContain('/admin/organizations');
    expect($urls)->not->toContain('/admin/service/plans');
    expect($urls)->not->toContain('/admin/service/shared-apps');
});

it('keeps the multi-tenant pages in the admin menu in multi-tenant mode', function () {
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    $urls = tenancyMenuUrls('admin');

    expect($urls)->toContain('/admin/organizations');
    expect($urls)->toContain('/admin/service/plans');
});

it('takes the subscription out of the organization menu in single mode', function () {
    useSingleTenancy();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    $urls = tenancyMenuUrls('organization');

    expect($urls)->not->toContain('/subscription');
    expect($urls)->not->toContain('/subscription/plans');
    expect($urls)->toContain('/apps');
});

it('keeps the subscription in the organization menu in multi-tenant mode', function () {
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    expect(tenancyMenuUrls('organization'))->toContain('/subscription');
});

it('has nothing to bill in single mode', function () {
    useSingleTenancy();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    expect(Gate::allows('has-billing-account'))->toBeFalse();
});

it('never deletes, unsubscribes or reactivates the single organization', function () {
    useSingleTenancy();
    $user = User::where('username', 'demo')->firstOrFail();
    $organization = Organization::find(1);
    $this->actingAs($user);

    $organization->status = 'deactivated';
    $organization->save();
    expect(Gate::allows('delete-organization', $organization))->toBeFalse();

    $organization->status = 'active';
    $organization->save();
    expect(Gate::allows('unsubscribe', $organization))->toBeFalse();

    $organization->status = 'deactivating';
    $organization->save();
    expect(Gate::allows('resubscribe', $organization))->toBeFalse();
});

it('still lets a multi-tenant install delete and unsubscribe organizations', function () {
    $user = User::where('username', 'demo')->firstOrFail();
    $organization = Organization::find(1);
    $this->actingAs($user);

    $organization->status = 'deactivated';
    $organization->save();
    expect(Gate::allows('delete-organization', $organization))->toBeTrue();

    $organization->status = 'active';
    $organization->save();
    expect(Gate::allows('unsubscribe', $organization))->toBeTrue();
});

it('switches the multi-organization feature flags off for the frontend in single mode', function () {
    config(['toggle.flags.shared-apps' => true, 'toggle.flags.sub-organizations' => true]);
    useSingleTenancy();

    $this->actingAs(User::where('username', 'demo')->firstOrFail())
        ->get('/settings/organization')
        ->assertInertia(fn ($page) => $page
            ->where('flags.sharedApps', false)
            ->where('flags.subOrganizations', false));
});

it('does not offer the multi-organization features in single mode', function () {
    useSingleTenancy();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    $this->get('/admin/settings/features')
        ->assertInertia(fn ($page) => $page->where('features', fn ($features) => collect($features)->pluck('key')->sort()->values()->all() === ['app-backups', 'domain-registrar', 'emails']));

    $this->put('/admin/settings/features', ['features' => ['app-backups' => true, 'domain-registrar' => false, 'emails' => false]])
        ->assertSessionHasNoErrors();
});

it('offers every feature in multi-tenant mode', function () {
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    $this->get('/admin/settings/features')
        ->assertInertia(fn ($page) => $page->where('features', fn ($features) => collect($features)->pluck('key')->sort()->values()->all() === ['app-backups', 'domain-registrar', 'emails', 'shared-apps', 'sub-organizations']));
});

it('keeps announcements off the dashboard and out of the menu in single mode', function () {
    $announcement = Announcement::create(['title' => 'Maintenance', 'short_description' => 'x', 'description' => 'x']);
    useSingleTenancy();

    $this->actingAs(User::where('username', 'demo')->firstOrFail())
        ->get("/announcements/{$announcement->id}")
        ->assertNotFound();

    $this->get('/')
        ->assertInertia(fn ($page) => $page->has('announcements', 0));

    expect(tenancyMenuUrls('admin'))->not->toContain('/admin/service/announcements');
});
