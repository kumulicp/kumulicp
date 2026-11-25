<?php

namespace Tests\Feature\Migrations;

use App\AppInstance;
use App\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemoveUpdateRecommendedFromAppInstancesSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_11_25_000000_remove_update_recommended_from_app_instances_settings.php');
    }

    private function appInstance(Organization $organization, string $name, ?array $settings): int
    {
        return DB::table('app_instances')->insertGetId([
            'name' => $name,
            'label' => $name,
            'application_id' => 1,
            'version_id' => 1,
            'organization_id' => $organization->id,
            'status' => 'active',
            'settings' => $settings === null ? null : json_encode($settings),
        ]);
    }

    public function test_it_takes_the_recommended_steps_out_of_the_settings_and_leaves_the_rest()
    {
        $organization = Organization::factory()->create();

        $recommended = $this->appInstance($organization, 'recommended', [
            'update' => ['recommended' => ['ldap-groups', 'sso']],
            'configurations' => ['keep' => 'me'],
        ]);
        $other_update_settings = $this->appInstance($organization, 'other', [
            'update' => ['recommended' => ['sso'], 'something' => 'else'],
        ]);
        $nothing_to_remove = $this->appInstance($organization, 'nothing', ['configurations' => ['keep' => 'me']]);
        $no_settings = $this->appInstance($organization, 'none', null);

        $this->migration()->up();

        // The update key goes with it when nothing else was kept there
        $this->assertSame(['configurations' => ['keep' => 'me']], AppInstance::find($recommended)->settings);
        $this->assertSame(['update' => ['something' => 'else']], AppInstance::find($other_update_settings)->settings);
        $this->assertSame(['configurations' => ['keep' => 'me']], AppInstance::find($nothing_to_remove)->settings);
        $this->assertNull(AppInstance::find($no_settings)->settings);
    }

    public function test_it_can_be_run_again()
    {
        $organization = Organization::factory()->create();
        $id = $this->appInstance($organization, 'recommended', ['update' => ['recommended' => ['sso']], 'keep' => 1]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['keep' => 1], AppInstance::find($id)->settings);
    }
}
