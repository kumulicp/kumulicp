<?php

use App\AppChart;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\AppVersionGroup;
use App\Enums\VersionTestRunStatus;
use App\Organization;
use App\Support\VersionTesting\VersionFingerprint;
use App\User;
use App\VersionTestRun;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();

    $this->actingAs(User::find(1));

    $this->application = Application::factory()->create();
    $this->chart = AppChart::factory()->default()->for($this->application)->create();
    $this->track_a = $this->chart->defaultVersionGroup();
    $this->track_b = AppVersionGroup::factory()->for($this->chart, 'chart')->create(['name' => 'Track B']);

    // Plans of track A and of track B, as customers can pick them
    $this->plan_a = AppPlan::factory()->create(['application_id' => $this->application->id]);
    $this->plan_b = AppPlan::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $this->track_b->id]);
});

function trackVersion(AppVersionGroup $group, string $name, string $status = 'deactivated'): AppVersion
{
    return AppVersion::factory()->create([
        'application_id' => $group->chart->application_id,
        'version_group_id' => $group->id,
        'name' => $name,
        'status' => $status,
    ]);
}

function approvedTest(AppVersion $version): void
{
    $run = new VersionTestRun;
    $run->application_id = $version->application_id;
    $run->app_version_id = $version->id;
    $run->status = VersionTestRunStatus::PASSED;
    $run->fingerprint = (new VersionFingerprint)->for($version);
    $run->finished_at = now();
    $run->save();
}

function installedOn(AppPlan $plan, AppVersion $version): AppInstance
{
    return AppInstance::factory()->create([
        'application_id' => $plan->application_id,
        'organization_id' => Organization::factory()->create()->id,
        'version_id' => $version->id,
        'plan_id' => $plan->id,
    ]);
}

it('enables a version without touching the enabled version of another group', function () {
    $a1 = trackVersion($this->track_a, '1.0', 'active');
    $b1 = trackVersion($this->track_b, '2.0');
    approvedTest($b1);

    $this->get("/admin/apps/{$this->application->slug}/versions/{$b1->id}/enable")->assertSessionHas('success');

    expect($b1->fresh()->status)->toBe('active')
        ->and($a1->fresh()->status)->toBe('active');
});

it('still makes the enabled version of its own group give way', function () {
    $a1 = trackVersion($this->track_a, '1.0', 'active');
    $a2 = trackVersion($this->track_a, '1.1');
    approvedTest($a2);

    $this->get("/admin/apps/{$this->application->slug}/versions/{$a2->id}/enable")->assertSessionHas('success');

    expect($a2->fresh()->status)->toBe('active')
        ->and($a1->fresh()->status)->toBe('deactivated');
});

it('switches the app off only when no group has an enabled version left', function () {
    $this->application->update(['enabled' => true]);
    $a1 = trackVersion($this->track_a, '1.0', 'active');
    $b1 = trackVersion($this->track_b, '2.0', 'active');

    $this->get("/admin/apps/{$this->application->slug}/versions/{$b1->id}/disable");
    expect($this->application->fresh()->enabled)->toBeTrue();

    $this->get("/admin/apps/{$this->application->slug}/versions/{$a1->id}/disable");
    expect($this->application->fresh()->enabled)->toBeFalse();
});

it('gives an app the enabled version of its default group, and a plan that of its own group', function () {
    $a1 = trackVersion($this->track_a, '1.0', 'active');
    $b1 = trackVersion($this->track_b, '2.0', 'active');

    expect($this->application->fresh()->active_version()->is($a1))->toBeTrue()
        ->and($this->plan_a->fresh()->activeVersion()->is($a1))->toBeTrue()
        ->and($this->plan_b->fresh()->activeVersion()->is($b1))->toBeTrue();
});

it('has no version for a plan whose group has none enabled', function () {
    trackVersion($this->track_a, '1.0', 'active');
    trackVersion($this->track_b, '2.0');

    expect($this->plan_b->fresh()->activeVersion())->toBeNull();
});

it('finds installs behind a version only among the plans that use its group', function () {
    $a_old = trackVersion($this->track_a, '1.0');
    $a_new = trackVersion($this->track_a, '1.1', 'active');
    $b_old = trackVersion($this->track_b, '2.0');
    $b_new = trackVersion($this->track_b, '2.1', 'active');

    $on_a = installedOn($this->plan_a, $a_old);
    $on_b = installedOn($this->plan_b, $b_old);

    expect($this->application->instancesBehind($a_new)->pluck('id')->all())->toBe([$on_a->id])
        ->and($this->application->instancesBehind($b_new)->pluck('id')->all())->toBe([$on_b->id]);
});

it('counts an install with no plan with the default group', function () {
    $a_old = trackVersion($this->track_a, '1.0');
    $a_new = trackVersion($this->track_a, '1.1', 'active');
    $planless = AppInstance::factory()->create([
        'application_id' => $this->application->id,
        'organization_id' => Organization::factory()->create()->id,
        'version_id' => $a_old->id,
    ]);

    expect($this->application->instancesBehind($a_new)->pluck('id')->all())->toBe([$planless->id]);
});

it('counts a plan that moved to another group as behind that group', function () {
    $a_old = trackVersion($this->track_a, '1.0');
    $b_new = trackVersion($this->track_b, '2.0', 'active');
    $install = installedOn($this->plan_a, $a_old);

    $this->plan_a->update(['version_group_id' => $this->track_b->id]);

    expect($this->application->instancesBehind($b_new)->pluck('id')->all())->toBe([$install->id]);
});

it('only upgrades an install to a version of its plan group', function () {
    $organization = Organization::factory()->create();
    $a1 = trackVersion($this->track_a, '1.0');
    $b1 = trackVersion($this->track_b, '2.0');
    $install = AppInstance::factory()->create([
        'application_id' => $this->application->id,
        'organization_id' => $organization->id,
        'version_id' => $a1->id,
        'plan_id' => $this->plan_a->id,
    ]);

    $this->get("/admin/organizations/{$organization->id}/apps/{$install->id}/upgrade/{$b1->id}")
        ->assertSessionHas('error', __('admin.organizations.apps.denied.version_group'));
});

it('scopes the plans a version test covers to its group, and to all of them for a group no plan uses', function () {
    $b1 = trackVersion($this->track_b, '2.0');
    $empty = AppVersionGroup::factory()->for($this->chart, 'chart')->create();
    $unused = trackVersion($empty, '3.0');

    expect($b1->testPlansQuery()->pluck('id')->all())->toBe([$this->plan_b->id])
        ->and($unused->testPlansQuery()->pluck('id')->sort()->values()->all())->toBe([$this->plan_a->id, $this->plan_b->id]);
});

it('keeps a test of a version valid when a plan of another group changes', function () {
    $b1 = trackVersion($this->track_b, '2.0');
    $before = (new VersionFingerprint)->for($b1);

    $this->plan_a->update(['name' => 'Renamed']);

    expect((new VersionFingerprint)->for($b1->fresh()))->toBe($before);
});

describe('groups', function () {
    it('adds, renames and deletes a group of the default chart', function () {
        $this->post("/admin/apps/{$this->application->slug}/version-groups", ['name' => 'Nextcloud 33'])->assertSessionDoesntHaveErrors();

        $group = $this->chart->versionGroups()->where('name', 'Nextcloud 33')->firstOrFail();
        expect($group->slug)->toBe('nextcloud-33');

        $this->put("/admin/apps/{$this->application->slug}/version-groups/{$group->id}", ['name' => 'Nextcloud 34']);
        expect($group->fresh()->name)->toBe('Nextcloud 34');

        $this->delete("/admin/apps/{$this->application->slug}/version-groups/{$group->id}");
        expect(AppVersionGroup::find($group->id))->toBeNull();
    });

    it('keeps the default group, and a group that still has versions or plans', function () {
        trackVersion($this->track_b, '2.0');

        $this->delete("/admin/apps/{$this->application->slug}/version-groups/{$this->track_a->id}")->assertSessionHas('error');
        $this->delete("/admin/apps/{$this->application->slug}/version-groups/{$this->track_b->id}")->assertSessionHas('error');

        expect(AppVersionGroup::find($this->track_a->id))->not->toBeNull()
            ->and(AppVersionGroup::find($this->track_b->id))->not->toBeNull();
    });

    it('does not touch a group of another app', function () {
        $foreign = AppVersionGroup::factory()->create();

        $this->put("/admin/apps/{$this->application->slug}/version-groups/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
    });

    it('adds a version to the group chosen, and refuses a name the group already has', function () {
        $this->post("/admin/apps/{$this->application->slug}/versions", ['version' => '5.0', 'copy_from' => 'none', 'version_group_id' => $this->track_b->id])
            ->assertSessionDoesntHaveErrors();

        expect(AppVersion::where('name', '5.0')->first()->version_group_id)->toBe($this->track_b->id);

        $this->post("/admin/apps/{$this->application->slug}/versions", ['version' => '5.0', 'copy_from' => 'none', 'version_group_id' => $this->track_b->id])
            ->assertSessionHasErrors('version');

        // The same version in another group is another version
        $this->post("/admin/apps/{$this->application->slug}/versions", ['version' => '5.0', 'copy_from' => 'none', 'version_group_id' => $this->track_a->id])
            ->assertSessionDoesntHaveErrors();
        $version = AppVersion::where('name', '5.0')->where('version_group_id', $this->track_a->id)->firstOrFail();

        $this->get("/admin/apps/{$this->application->slug}/versions/{$version->id}")
            ->assertInertia(fn ($page) => $page->where('version.id', $version->id));
    });

    it('moves a version that is not enabled to another group of its chart', function () {
        $registry = \App\RepoSecret::factory()->create();
        $version = trackVersion($this->track_a, '1.0');
        $payload = ['version' => '1.0', 'image_registry_id' => $registry->id, 'announcement_location' => 'none', 'version_group_id' => $this->track_b->id];

        $this->post("/admin/apps/{$this->application->slug}/versions/{$version->id}", $payload)->assertSessionDoesntHaveErrors();
        expect($version->fresh()->version_group_id)->toBe($this->track_b->id);

        $version->update(['status' => 'active', 'version_group_id' => $this->track_a->id]);
        $this->post("/admin/apps/{$this->application->slug}/versions/{$version->id}", $payload)->assertSessionHasErrors('version_group_id');
        expect($version->fresh()->version_group_id)->toBe($this->track_a->id);
    });

    it('lists the versions of one group at a time', function () {
        trackVersion($this->track_a, '1.0');
        trackVersion($this->track_b, '2.0');

        $this->get("/admin/apps/{$this->application->slug}/versions")
            ->assertInertia(fn ($page) => $page->has('versions', 1)->where('versions.0.version', '1.0')->has('groups', 2));

        $this->get("/admin/apps/{$this->application->slug}/versions?group={$this->track_b->id}")
            ->assertInertia(fn ($page) => $page->has('versions', 1)->where('versions.0.version', '2.0')->where('group_id', $this->track_b->id));
    });
});
