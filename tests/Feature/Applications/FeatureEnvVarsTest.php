<?php

use App\AppInstance;
use App\Application;
use App\Integrations\Applications\AppFeature;
use App\Integrations\Applications\Feature;
use App\Integrations\ServerManagers\Kubernetes\Charts\Chart;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;
use Tests\Support\TestSupports;

// A feature that has its own env var
function envVarFeature(): AppFeature
{
    return new class extends AppFeature implements Feature
    {
        public $name = 'withvar';

        public $label = 'With a var';

        public $description = 'Has an env var';

        public $category = 'apps';

        public $type = 'checkbox';

        public $input = 'enable_disable';

        public $var_name = 'WITH_VAR';

        public function __construct(?AppInstance $app_instance = null)
        {
            $this->app_instance = $app_instance;
        }

        public function pricing_options()
        {
            return [];
        }

        public function status(AppInstance $app_instance)
        {
            return false;
        }
    };
}

// A feature that does not declare an env var at all, like the ones that only decide which apps get installed
function featureWithoutEnvVar(): AppFeature
{
    return new class extends AppFeature implements Feature
    {
        public $name = 'novar';

        public $label = 'No var';

        public $description = 'Has no env var';

        public $category = 'apps';

        public $type = 'checkbox';

        public $input = 'enable_disable';

        public function __construct(?AppInstance $app_instance = null)
        {
            $this->app_instance = $app_instance;
        }

        public function admin_settings()
        {
            return ['novar_url' => ['type' => 'text', 'input_type' => 'text', 'name' => 'novar_url', 'label' => 'URL']];
        }

        public function pricing_options()
        {
            return [];
        }

        public function status(AppInstance $app_instance)
        {
            return false;
        }
    };
}

function envVarChart(AppInstance $app_instance): Chart
{
    $org_server = new OrgServer;
    $org_server->setRelation('server', (new Server)->forceFill(['interface' => 'kubernetes']));
    $app_instance->setRelation('web_server', $org_server);

    return new Chart($app_instance->organization, $app_instance);
}

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $demoApp = Application::where('slug', 'demo_app')->first();
    $this->instance = $demoApp->instances()->first();
    $this->plan = $this->instance->plan;

    foreach ([envVarFeature(), featureWithoutEnvVar()] as $feature) {
        ApplicationFacade::profile('demo_app')->addFeature($feature);
    }
});

it('gives an enabled feature with an env var the value 1', function () {
    ApplicationFacade::plan($this->plan)->updateFeatures(['withvar' => ['status' => 'enabled']]);

    expect(collect(envVarChart($this->instance->fresh())->convertFeaturesToEnvVars())->firstWhere('name', 'WITH_VAR'))
        ->toBe(['name' => 'WITH_VAR', 'value' => '1']);
});

it('takes a feature the admin page sent with its settings as null', function () {
    // A feature with no settings of its own comes back from the page as `settings: null`
    $stored = ApplicationFacade::plan($this->plan)->updateFeatures(['novar' => ['status' => 'enabled', 'settings' => null]]);

    expect($stored['novar']['status'])->toBe('enabled')
        ->and($stored['novar']['settings'])->toBe([]);
});

it('gives a disabled feature with an env var the value 0', function () {
    ApplicationFacade::plan($this->plan)->updateFeatures(['withvar' => ['status' => 'disabled']]);

    expect(collect(envVarChart($this->instance->fresh())->convertFeaturesToEnvVars())->firstWhere('name', 'WITH_VAR'))
        ->toBe(['name' => 'WITH_VAR', 'value' => '0']);
});

it('leaves out the toggle of a feature that has no env var, without an empty or undefined name', function () {
    ApplicationFacade::plan($this->plan)->updateFeatures(['novar' => ['status' => 'enabled']]);

    $env = collect(envVarChart($this->instance->fresh())->convertFeaturesToEnvVars());

    expect($env->pluck('name')->filter(fn ($name) => $name === '' || $name === null))->toBeEmpty();
});

it('still passes the settings of an enabled feature that has no env var of its own', function () {
    ApplicationFacade::plan($this->plan)->updateFeatures(['novar' => ['status' => 'enabled', 'settings' => ['novar_url' => 'https://example.test']]]);

    $env = collect(envVarChart($this->instance->fresh())->convertFeaturesToEnvVars());

    expect($env->firstWhere('name', 'NOVAR_URL'))->toBe(['name' => 'NOVAR_URL', 'value' => 'https://example.test']);
});
