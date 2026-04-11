<?php

namespace Tests\Feature\Applications;

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Organization;
use App\Plan;
use App\Support\Facades\Action;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NextcloudActivationTes extends TestCase
{
    use RefreshDatabase;

    public function test()
    {
        $this->withoutExceptionHandling();
        $app_instance = AppInstance::find(1);
        if ($app_instance) {
            $app_instance->delete();
        }
        $user = User::find(1);
        $plan = Plan::find(1);
        $organization = Organization::find(1);
        $app = Application::where('slug', 'nextcloud')->first();

        $app_plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'web_server_id' => 1,
            'settings' => [
                'base' => [
                    'max' => null,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'basic' => [
                    'max' => null,
                    'name' => null,
                    'price' => null,
                    'amount' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'storage' => [
                    'max' => null,
                    'price' => null,
                    'amount' => '5',
                    'price_id' => null,
                ],
                'features' => [
                    'deck' => [
                        'name' => 'deck',
                        'price' => null,
                        'status' => 'optional',
                        'settings' => [],
                        'price_id' => null,
                    ],
                    'spreed' => [
                        'name' => 'spreed',
                        'price' => null,
                        'status' => 'optional',
                        'settings' => [],
                        'price_id' => null,
                    ],
                    'calendar' => [
                        'name' => 'calendar',
                        'price' => null,
                        'status' => 'optional',
                        'settings' => [],
                        'price_id' => null,
                    ],
                    'contacts' => [
                        'name' => 'contacts',
                        'price' => null,
                        'status' => 'optional',
                        'settings' => [],
                        'price_id' => null,
                    ],
                    'php_settings' => [
                        'name' => 'php_settings',
                        'price' => null,
                        'status' => 'disabled',
                        'settings' => [
                            'PHP_MEMORY_LIMIT' => null,
                            'PHP_UPLOAD_LIMIT' => null,
                        ],
                        'price_id' => null,
                    ],
                ],
                'standard' => [
                    'max' => 1,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'configurations' => [
                    'redis' => [
                        'master' => [
                            'persistence' => [
                                'enabled' => true,
                                'storageClass' => 'longhorn',
                                'size' => '1Gi',
                                'numberOfReplicas' => 1,
                            ],
                        ],
                        'enabled' => true,
                        'replica' => [
                            'persistence' => [
                                'enabled' => true,
                                'storageClass' => 'longhorn',
                                'size' => '1Gi',
                                'numberOfReplicas' => 1,
                            ],
                            'replicaCount' => 1,
                        ],
                    ],
                    'cronjob' => false,
                    'mariadb' => [
                        'db' => [
                            'database' => 'nextcloud',
                            'password' => 'changeme',
                            'username' => 'nextcloud',
                        ],
                        'enabled' => true,
                        'primary' => [
                            'persistence' => [
                                'size' => '2Gi',
                                'enabled' => true,
                                'accessMode' => 'ReadWriteOnce',
                                'storageClass' => 'longhorn',
                                'existingClaim' => '',
                                'numberOfReplicas' => 1,
                            ],
                        ],
                        'architecture' => 'standalone',
                        'existingSecret' => '',
                    ],
                    'username' => 'support',
                    'hpa-enabled' => false,
                    'hpa-maxPods' => 10,
                    'hpa-minPods' => 1,
                    'rbac-enabled' => true,
                    'replicaCount' => 1,
                    'metrics-https' => false,
                    'ingress-enabled' => true,
                    'metrics-enabled' => false,
                    'hpa-cputhreshold' => 60,
                    'persistence-enabled' => true,
                    'persistence-numberOfReplicas' => 1,
                    'resources-limits-cpu' => '1250m',
                    'startupProbe-enabled' => true,
                    'nextcloud-mail-domain' => null,
                    'nextcloud-mail-enabled' => false,
                    'persistence-accessMode' => 'ReadWriteOnce',
                    'resources-requests-cpu' => '500m',
                    'nextcloud-strategy-type' => 'RollingUpdate',
                    'resources-limits-memory' => '1Gi',
                    'externalDatabase-enabled' => false,
                    'nextcloud-mail-smtp-host' => null,
                    'nextcloud-mail-smtp-name' => null,
                    'nextcloud-mail-smtp-port' => 465,
                    'persistence-storageClass' => 'longhorn',
                    'persistence-existingClaim' => null,
                    'resources-requests-memory' => '500Mi',
                    'nextcloud-mail-fromAddress' => null,
                    'nextcloud-mail-smtp-secure' => 'ssl',
                    'startupProbe-periodSeconds' => 10,
                    'startupProbe-timeoutSeconds' => 10,
                    'nextcloud-mail-smtp-authtype' => 'PLAIN',
                    'nextcloud-mail-smtp-password' => '',
                    'startupProbe-initialDelaySeconds' => 10,
                    'ingress-annotation-cluster_issuer' => 'issuer  ',
                ],
            ],
        ]);

        $activate = Action::execute(new ApplicationActivate(organization: $organization, app: $app, plan: $app_plan));
        $activate->status = 'in_progress';
        $activate->save();

        Action::run($activate);

        $complete = false;

        while ($complete == false) {
            Action::complete($activate);
            $activate->refresh();
            $complete = (in_array($activate->status, ['complete', 'failed']));
            sleep(10);
        }
        $app_instance = AppInstance::where('application_id', 1)->first();
        $organization = OrganizationFacade::setOrganization($app_instance->organization);
        $this->assertEquals($app_instance->status, 'active');

        $update = Action::execute(new ApplicationUpdate($app_instance));

        Action::run($update);

        $complete = false;

        while ($complete == false) {
            Action::complete($update);
            $update->refresh();
            $complete = (in_array($update->status, ['complete', 'failed']));
            sleep(10);
        }
        $app_instance->refresh();
        $this->assertEquals($app_instance->status, 'active');

        $upgrade = Action::execute(new ApplicationUpgrade($app_instance, $app_instance->version));

        Action::run($upgrade);

        $complete = false;

        while ($complete == false) {
            Action::complete($upgrade);
            $upgrade->refresh();
            $complete = (in_array($upgrade->status, ['complete', 'failed']));
            sleep(10);
        }
        $app_instance->refresh();
        $this->assertEquals($app_instance->status, 'active');

        $delete = Action::execute(new ApplicationDelete($app_instance));

        Action::run($delete);
        $delete->refresh();
        $delete->status = 'in_progress';
        $delete->save();

        $complete = false;

        while ($complete == false) {
            Action::complete($delete);
            $delete_task = Task::find($delete->id);
            $complete = is_null($delete_task);
            sleep(10);
        }
        $app_instance = AppInstance::find($app_instance->id);
        $this->assertNull($app_instance);
    }
}
