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
 * WordPress application lifecycle + WordPress-specific tests.
 *
 * Default: runs against FakeServerManager (no real Rancher required).
 * Real-server: set SERVER_MANAGER=rancher, then call skipIfNotServerManager('rancher')
 * to opt individual tests in to real infrastructure.
 *
 * WordPress-specific tests (user roles, WP admin API) should call
 * skipIfNotServerManager('rancher') since they require a live WordPress instance.
 */
class WordpressActivationTest extends TestCase
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

    public function test_wordpress_lifecycle(): void
    {
        $this->withoutExceptionHandling();

        $support = new TestSupports;
        $support->seed();

        $org = Organization::find(1);
        $app = Application::where('slug', 'wordpress')->first();

        $plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'web_server_id' => 1,
            'settings' => [
                'base' => ['max' => 0, 'price' => 0, 'storage' => 0, 'price_id' => null],
                'basic' => ['max' => 0, 'name' => null, 'price' => 0, 'amount' => 0, 'storage' => 0, 'price_id' => null],
                'email' => [],
                'storage' => ['max' => 0, 'price' => 0, 'amount' => 0, 'price_id' => null],
                'standard' => ['max' => 0, 'price' => 0, 'storage' => 0, 'price_id' => null],
                'application' => [],
                'configurations' => [
                    'mariadb' => [
                        'auth' => [
                            'password' => 'password',
                            'rootPassword' => 'root_password',
                            'username' => 'bn_wordpress',
                            'database' => 'bitnami_wordpress',
                        ],
                        'enabled' => true,
                        'primary' => [
                            'persistence' => [
                                'size' => '4Gi',
                                'enabled' => true,
                                'storageClass' => 'longhorn',
                                'accessMode' => ['ReadWriteOnce'],
                            ],
                        ],
                        'architecture' => 'standalone',
                    ],
                    'image-debug' => false,
                    'ingress-enabled' => true,
                    'wordpress-email' => null,
                    'image-pullPolicy' => 'IfNotPresent',
                    'wordpress-plugins' => null,
                    'wordpress-lastname' => 'Support',
                    'wordpress-username' => 'support',
                    'persistence-enabled' => true,
                    'updateStrategy-type' => 'RollingUpdate',
                    'wordpress-firstname' => 'Wordpress',
                    'persistence-accessMode' => 'ReadWriteOnce',
                    'persistence-accessModes' => ['ReadWriteOnce'],
                    'persistence-storageClass' => 'longhorn',
                    'persistence-existingClaim' => null,
                    'updateStrategy-rollingUpdate' => null,
                    'ingress-annotation-cluster_issuer' => 'letsencrypt',
                    'customReadinessProbe-periodSeconds' => 10,
                    'customReadinessProbe-timeoutSeconds' => 5,
                    'customReadinessProbe-failureThreshold' => 6,
                    'customReadinessProbe-successThreshold' => 1,
                    'customReadinessProbe-initialDelaySeconds' => 10,
                    'ingress-ingress-annotation-traefik_middlewares' => 'default-middlewares@kubernetescrd',
                ],
            ],
        ]);

        $instance = $this->runActivate($plan, $org, $app);
        $this->runUpdate($instance);
        $this->runUpgrade($instance);
        $this->runDelete($instance);
    }

    /**
     * Tests that require a live WordPress instance go here.
     * They skip automatically unless SERVER_MANAGER=rancher.
     */
    public function test_wordpress_user_roles(): void
    {
        $this->skipIfNotServerManager('rancher');

        // TODO: test WordPress user role management via the real WP API
        // $wordpress = AppInstance::where('name', 'wordpress')->first();
        // $user = new WordpressUser($wordpress);
        // $this->assertNotNull($user->getUserID('support'));
        // $this->assertTrue($user->updateUserRoles('support', 'administrator'));
    }
}
