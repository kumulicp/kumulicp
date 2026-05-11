<?php

namespace Tests\Feature\Applications;

use App\Application;
use App\AppPlan;
use App\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * Nextcloud application lifecycle + Nextcloud-specific tests.
 *
 * Default: runs against FakeServerManager (no real Rancher required).
 * Real-server: set SERVER_MANAGER=rancher, then call skipIfNotServerManager('rancher')
 * to opt individual tests in to real infrastructure.
 *
 * Nextcloud-specific tests (group folders, app management, user provisioning via
 * the Nextcloud API) should call skipIfNotServerManager('rancher') since they
 * require a live Nextcloud instance.
 */
class NextcloudActivationTest extends TestCase
{
    use RefreshDatabase;
    use TestsApplicationLifecycle;
    use TestsWithServerInterfaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupFakeServerInterfaces();
        $this->fakeNotificationsAndMail();
    }

    protected function tearDown(): void
    {
        $this->restoreServerInterfaces();
        parent::tearDown();
    }

    public function test_nextcloud_lifecycle(): void
    {
        $this->withoutExceptionHandling();

        $support = new TestSupports;
        $support->seed();

        $org = Organization::find(1);
        $app = Application::where('slug', 'nextcloud')->first();

        $plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'web_server_id' => 1,
            'settings' => [
                'base' => ['max' => null, 'price' => null, 'storage' => null, 'price_id' => null],
                'basic' => ['max' => null, 'name' => null, 'price' => null, 'amount' => null, 'storage' => null, 'price_id' => null],
                'storage' => ['max' => null, 'price' => null, 'amount' => '5', 'price_id' => null],
                'features' => [
                    'deck' => ['name' => 'deck', 'price' => null, 'status' => 'optional', 'settings' => [], 'price_id' => null],
                    'spreed' => ['name' => 'spreed', 'price' => null, 'status' => 'optional', 'settings' => [], 'price_id' => null],
                    'calendar' => ['name' => 'calendar', 'price' => null, 'status' => 'optional', 'settings' => [], 'price_id' => null],
                    'contacts' => ['name' => 'contacts', 'price' => null, 'status' => 'optional', 'settings' => [], 'price_id' => null],
                    'php_settings' => [
                        'name' => 'php_settings', 'price' => null, 'status' => 'disabled',
                        'settings' => ['PHP_MEMORY_LIMIT' => null, 'PHP_UPLOAD_LIMIT' => null],
                        'price_id' => null,
                    ],
                ],
                'standard' => ['max' => 1, 'price' => null, 'storage' => null, 'price_id' => null],
                'configurations' => [
                    'redis' => [
                        'enabled' => true,
                        'master' => ['persistence' => ['enabled' => true, 'storageClass' => 'longhorn', 'size' => '1Gi', 'numberOfReplicas' => 1]],
                        'replica' => ['persistence' => ['enabled' => true, 'storageClass' => 'longhorn', 'size' => '1Gi', 'numberOfReplicas' => 1], 'replicaCount' => 1],
                    ],
                    'cronjob' => false,
                    'mariadb' => [
                        'db' => ['database' => 'nextcloud', 'password' => 'changeme', 'username' => 'nextcloud'],
                        'enabled' => true,
                        'primary' => [
                            'persistence' => [
                                'size' => '2Gi', 'enabled' => true,
                                'accessMode' => 'ReadWriteOnce', 'storageClass' => 'longhorn',
                                'existingClaim' => '', 'numberOfReplicas' => 1,
                            ],
                        ],
                        'architecture' => 'standalone',
                        'existingSecret' => '',
                    ],
                    'username' => 'support',
                    'ingress-enabled' => true,
                    'replicaCount' => 1,
                    'rbac-enabled' => true,
                    'persistence-enabled' => true,
                    'persistence-accessMode' => 'ReadWriteOnce',
                    'persistence-storageClass' => 'longhorn',
                    'persistence-existingClaim' => null,
                    'persistence-numberOfReplicas' => 1,
                    'nextcloud-strategy-type' => 'RollingUpdate',
                    'ingress-annotation-cluster_issuer' => 'letsencrypt',
                ],
            ],
        ]);

        $instance = $this->runActivate($plan, $org, $app);
        $this->runUpdate($instance);
        $this->runUpgrade($instance);
        $this->runDelete($instance);
    }

    /**
     * Tests that require a live Nextcloud instance go here.
     * They skip automatically unless SERVER_MANAGER=rancher.
     */
    public function test_nextcloud_group_folders(): void
    {
        $this->skipIfNotServerManager('rancher');

        // TODO: test Nextcloud group folder creation/deletion via the real NC API
    }

    public function test_nextcloud_app_management(): void
    {
        $this->skipIfNotServerManager('rancher');

        // TODO: test enabling/disabling Nextcloud apps via the real NC API
    }
}
