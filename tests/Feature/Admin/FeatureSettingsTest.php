<?php

namespace Tests\Feature\Admin;

use App\Organization;
use App\Support\FeatureFlags;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use OffloadProject\Toggle\Facades\Toggle;
use Tests\Support\TestSupports;
use Tests\TestCase;

class FeatureSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $support = new TestSupports;
        $support->seed();
        $this->admin = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($this->admin);
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, array<string, bool>>
     */
    private function payload(array $overrides = []): array
    {
        $features = array_fill_keys(FeatureFlags::keys(), false);

        return ['features' => array_merge($features, $overrides)];
    }

    public function test_guest_is_redirected_from_feature_settings()
    {
        auth()->logout();

        $this->get('/admin/settings/features')->assertRedirect('/login');
    }

    public function test_page_lists_every_feature_with_its_description_and_beta_marker()
    {
        $this->get('/admin/settings/features')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Settings/FeatureSettings')
                ->has('features', count(FeatureFlags::keys()))
                ->where('features', function ($features) {
                    $features = collect($features)->keyBy('key');

                    return $features['sub-organizations']['beta'] === true
                        && $features['app-backups']['beta'] === true
                        && $features['shared-apps']['beta'] === false
                        && $features['emails']['beta'] === false
                        && $features->every(fn ($feature) => $feature['label'] !== '' && $feature['description'] !== ''
                            && ! str_starts_with($feature['label'], 'admin.settings.features'));
                }));
    }

    public function test_page_shows_the_value_from_the_env_default_until_one_is_saved()
    {
        config(['toggle.flags.shared-apps' => true]);

        $this->get('/admin/settings/features')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('features', fn ($features) => collect($features)->firstWhere('key', 'shared-apps')['enabled'] === true
                    && collect($features)->firstWhere('key', 'emails')['enabled'] === false));
    }

    public function test_update_stores_each_flag_in_the_database()
    {
        $this->put('/admin/settings/features', $this->payload(['app-backups' => true, 'emails' => true]))
            ->assertRedirect('admin/settings/features');

        $this->assertDatabaseHas('toggles', ['name' => 'app-backups', 'active' => true]);
        $this->assertDatabaseHas('toggles', ['name' => 'emails', 'active' => true]);
        $this->assertDatabaseHas('toggles', ['name' => 'shared-apps', 'active' => false]);
        $this->assertTrue(Toggle::active('app-backups'));
        $this->assertFalse(Toggle::active('sub-organizations'));
    }

    public function test_a_saved_value_wins_over_the_env_default()
    {
        config(['toggle.flags.app-backups' => true]);

        $this->put('/admin/settings/features', $this->payload(['app-backups' => false]))->assertRedirect();

        $this->assertFalse(Toggle::active('app-backups'));
        $this->get('/admin/server/backup_scheduler')->assertNotFound();
    }

    public function test_enabling_a_flag_opens_its_routes()
    {
        $this->get('/admin/server/backup_scheduler')->assertNotFound();

        $this->put('/admin/settings/features', $this->payload(['app-backups' => true]))->assertRedirect();

        $this->get('/admin/server/backup_scheduler')->assertOk();
    }

    public function test_a_flag_with_no_saved_value_follows_the_env_default()
    {
        $this->assertDatabaseMissing('toggles', ['name' => 'emails']);

        config(['toggle.flags.emails' => true]);
        $this->assertTrue(Toggle::active('emails'));
    }

    public function test_update_requires_every_flag_to_be_a_boolean()
    {
        $this->put('/admin/settings/features', ['features' => ['emails' => 'maybe']])
            ->assertSessionHasErrors(['features.emails', 'features.app-backups']);

        $this->assertSame(0, DB::table('toggles')->count());
    }

    public function test_unknown_flags_are_ignored()
    {
        $this->put('/admin/settings/features', $this->payload(['made-up' => true]))->assertRedirect();

        $this->assertDatabaseMissing('toggles', ['name' => 'made-up']);
    }

    public function test_the_flags_shared_with_the_frontend_reflect_the_saved_values()
    {
        $this->put('/admin/settings/features', $this->payload(['sub-organizations' => true]))->assertRedirect();

        $this->get('/admin/settings/features')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flags.subOrganizations', true)
                ->where('flags.emails', false));
    }

    public function test_changing_a_flag_clears_the_cached_menu_of_every_organization()
    {
        $slugs = Organization::pluck('slug');
        foreach ($slugs as $slug) {
            Cache::tags($slug)->put('menu_probe', ['stale'], null);
        }

        $this->put('/admin/settings/features', $this->payload(['emails' => true]))->assertRedirect();

        foreach ($slugs as $slug) {
            $this->assertFalse(Cache::tags($slug)->has('menu_probe'));
        }
    }

    public function test_saving_without_changing_a_flag_keeps_the_cached_menu()
    {
        $slug = Organization::firstOrFail()->slug;
        Cache::tags($slug)->put('menu_probe', ['cached'], null);

        $current = collect(FeatureFlags::all())->pluck('enabled', 'key')->all();
        $this->put('/admin/settings/features', ['features' => $current])->assertRedirect();

        $this->assertTrue(Cache::tags($slug)->has('menu_probe'));
    }
}
