<?php

namespace Tests\Feature\Applications;

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Integrations\Applications\Wordpress\API\User as WordpressUser;
use App\Organization;
use App\Plan;
use App\Support\Facades\Action;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class WordpressActivationTes extends TestCase
{
    use RefreshDatabase;

    public function test_wordpress_activation()
    {
        $this->withoutExceptionHandling();

        $support = new TestSupports;
        $support->seed();

        $app_instance = AppInstance::find(2);
        if ($app_instance) {
            $app_instance->delete();
        }
        $user = User::find(1);
        $plan = Plan::find(1);
        $organization = Organization::find(1);
        $app = Application::where('slug', 'wordpress')->first();

        $app_plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'web_server_id' => 1,
            'settings' => [
                'base' => ['max' => 0, 'price' => 0, 'storage' => 0, 'price_id' => null],
                'basic' => [
                    'max' => 0,
                    'name' => null,
                    'price' => 0,
                    'amount' => 0,
                    'storage' => 0,
                    'price_id' => null,
                ],
                'email' => [],
                'storage' => ['max' => 0, 'price' => 0, 'amount' => 0, 'price_id' => null],
                'standard' => [
                    'max' => 0,
                    'price' => 0,
                    'storage' => 0,
                    'price_id' => null,
                ],
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
        $app_instance = AppInstance::where('application_id', $app->id)->first();
        $organization = OrganizationFacade::setOrganization($app_instance->organization);
        $this->assertEquals($app_instance->status, 'active');

        $wordpress = AppInstance::where('name', 'wordpress')->first();

        // $user = new WordpressUser($wordpress);
        // dump($get = $user->getUserID('support'));
        // dump($update_roles = $user->updateUserRoles('support', 'administrator'));

        // TODO: Add tests that fail and more asserts

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
