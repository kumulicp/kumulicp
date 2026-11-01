<?php

namespace Tests\Feature\Migrations;

use App\AppInstance;
use App\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddDbPasswordToAppInstancesTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_10_31_000000_add_db_password_to_app_instances_table.php');
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

    private function rawDbPassword(int $id): ?string
    {
        return DB::table('app_instances')->where('id', $id)->value('db_password');
    }

    public function test_it_backfills_from_the_settings_password_and_then_the_organization_password()
    {
        $organization = Organization::factory()->create(['secretpw' => 'organization-secret']);
        $without_password = Organization::factory()->create(['secretpw' => null]);

        $encrypted = $this->appInstance($organization, 'encrypted', [
            'db_password' => Crypt::encryptString('settings-secret'),
            'configurations' => ['keep' => 'me'],
        ]);
        $plaintext = $this->appInstance($organization, 'plaintext', ['db_password' => 'plain-secret']);
        $legacy = $this->appInstance($organization, 'legacy', null);
        $nothing = $this->appInstance($without_password, 'nothing', []);

        Schema::dropColumns('app_instances', ['db_password']);
        $this->migration()->up();

        $this->assertSame('settings-secret', AppInstance::find($encrypted)->db_password);
        $this->assertSame('plain-secret', AppInstance::find($plaintext)->db_password);
        $this->assertSame('organization-secret', AppInstance::find($legacy)->db_password);
        $this->assertNull($this->rawDbPassword($nothing));

        // Nothing is stored in plaintext
        foreach ([$encrypted, $plaintext, $legacy] as $id) {
            $this->assertNotSame(Crypt::decryptString($this->rawDbPassword($id)), $this->rawDbPassword($id));
        }

        // The old location is cleared, other settings are untouched
        $this->assertSame(['configurations' => ['keep' => 'me']], AppInstance::find($encrypted)->settings);
        $this->assertNull(AppInstance::find($plaintext)->setting('db_password'));
    }

    public function test_down_moves_the_password_back_to_settings()
    {
        $organization = Organization::factory()->create();
        $id = $this->appInstance($organization, 'app', []);
        AppInstance::find($id)->forceFill(['db_password' => 'app-secret'])->save();

        $this->migration()->down();

        $settings = json_decode(DB::table('app_instances')->where('id', $id)->value('settings'), true);
        $this->assertSame('app-secret', Crypt::decryptString($settings['db_password']));
        $this->assertFalse(Schema::hasColumn('app_instances', 'db_password'));
    }
}
