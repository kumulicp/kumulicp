<?php

use App\AppInstance;
use App\Application;
use App\Integrations\Applications\AppFeature;
use App\Integrations\Applications\Feature;
use App\Integrations\ServerManagers\Rancher\Charts\Chart;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\SecretValue;
use App\User;
use Illuminate\Support\Facades\Log;
use Tests\Support\TestSupports;

// A feature with one plain and one secret admin setting
function secretTestFeature(): AppFeature
{
    return new class extends AppFeature implements Feature
    {
        public $name = 'secretfeat';

        public $label = 'Secret feature';

        public $description = 'A feature with a secret setting';

        public $category = 'apps';

        public $type = 'checkbox';

        public $input = 'enable_disable';

        public $var_name = 'SECRETFEAT';

        public function __construct(?AppInstance $app_instance = null)
        {
            $this->app_instance = $app_instance;
        }

        public function admin_settings()
        {
            return [
                'secretfeat_url' => ['type' => 'text', 'input_type' => 'text', 'name' => 'secretfeat_url', 'label' => 'URL'],
                'secretfeat_token' => ['type' => 'text', 'input_type' => 'password', 'name' => 'secretfeat_token', 'label' => 'Token', 'secret' => true],
            ];
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

// A chart whose app instance runs on the given server interface
function chartOn(AppInstance $app_instance, string $interface): Chart
{
    $org_server = new OrgServer;
    $org_server->setRelation('server', (new Server)->forceFill(['interface' => $interface]));
    $app_instance->setRelation('web_server', $org_server);

    return new Chart($app_instance->organization, $app_instance);
}

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->demoApp = Application::where('slug', 'demo_app')->first();
    $this->instance = $this->demoApp->instances()->first();
    $this->plan = $this->instance->plan;

    ApplicationFacade::profile('demo_app')->addFeature(secretTestFeature());
    $this->url = "/admin/apps/{$this->demoApp->slug}/plans/{$this->plan->id}";
});

// Custom plan secrets

it('stores plan secrets encrypted and reads them back for delivery', function () {
    $secrets = ApplicationFacade::plan($this->plan)->secrets();

    $secrets->update([['name' => 'API_KEY', 'value' => 'abc123'], ['name' => 'SMTP_PASSWORD', 'value' => 'hunter2']]);

    $stored = $this->plan->fresh()->settings['secrets'];

    expect($secrets->names())->toBe(['API_KEY', 'SMTP_PASSWORD'])
        ->and($stored['API_KEY'])->not->toBe('abc123')
        ->and(json_encode($stored))->not->toContain('abc123')->not->toContain('hunter2')
        ->and($secrets->values())->toBe(['API_KEY' => 'abc123', 'SMTP_PASSWORD' => 'hunter2']);
});

it('keeps a secret whose value is left blank and removes the ones not listed', function () {
    $secrets = ApplicationFacade::plan($this->plan)->secrets();
    $secrets->update([['name' => 'KEEP_ME', 'value' => 'one'], ['name' => 'DROP_ME', 'value' => 'two']]);

    $secrets->update([['name' => 'KEEP_ME', 'value' => '']]);

    expect($secrets->values())->toBe(['KEEP_ME' => 'one']);
});

it('only sends the names of plan secrets to the browser', function () {
    ApplicationFacade::plan($this->plan)->secrets()->update([['name' => 'API_KEY', 'value' => 'abc123']]);

    $response = $this->actingAs($this->user)->get("{$this->url}/secrets");

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Applications/Plans/PlanSecrets')
        ->where('secrets', ['API_KEY']));
    expect($response->getContent())->not->toContain('abc123');
});

it('does not leak plan secrets through the other plan pages', function () {
    ApplicationFacade::plan($this->plan)->secrets()->update([['name' => 'API_KEY', 'value' => 'abc123']]);
    $ciphertext = $this->plan->fresh()->settings['secrets']['API_KEY'];

    foreach (['', '/edit', '/features', '/configurations'] as $path) {
        $content = $this->actingAs($this->user)->get($this->url.$path)->assertOk()->getContent();

        expect($content)->not->toContain('abc123')->not->toContain(addslashes($ciphertext));
    }
});

it('reveals one saved secret on request, uncached and without logging its value', function () {
    ApplicationFacade::plan($this->plan)->secrets()->update([['name' => 'API_KEY', 'value' => 'abc123'], ['name' => 'OTHER', 'value' => 'zzz999']]);

    Log::spy();

    $response = $this->actingAs($this->user)->postJson("{$this->url}/secrets/reveal", ['name' => 'API_KEY']);

    $response->assertOk()->assertExactJson(['value' => 'abc123']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    Log::shouldHaveReceived('info')->once()->withArgs(fn ($message) => str_contains($message, 'API_KEY')
        && str_contains($message, $this->user->username)
        && ! str_contains($message, 'abc123'));
});

it('does not reveal a secret that does not exist, or one from another app\'s plan', function () {
    ApplicationFacade::plan($this->plan)->secrets()->update([['name' => 'API_KEY', 'value' => 'abc123']]);

    $this->actingAs($this->user)->postJson("{$this->url}/secrets/reveal", ['name' => 'MISSING'])->assertNotFound();
    $this->actingAs($this->user)->postJson("{$this->url}/secrets/reveal", [])->assertUnprocessable();

    $otherApp = Application::factory()->create();
    $this->actingAs($this->user)
        ->postJson("/admin/apps/{$otherApp->slug}/plans/{$this->plan->id}/secrets/reveal", ['name' => 'API_KEY'])
        ->assertNotFound();
});

it('does not reveal secrets to guests', function () {
    ApplicationFacade::plan($this->plan)->secrets()->update([['name' => 'API_KEY', 'value' => 'abc123']]);

    $this->postJson("{$this->url}/secrets/reveal", ['name' => 'API_KEY'])->assertUnauthorized();
});

it('saves plan secrets from the page', function () {
    $this->actingAs($this->user)
        ->put("{$this->url}/secrets", ['secrets' => [['name' => 'API_KEY', 'value' => 'abc123']]])
        ->assertRedirect("{$this->url}/secrets");

    expect(ApplicationFacade::plan($this->plan->fresh())->secrets()->values())->toBe(['API_KEY' => 'abc123']);
});

it('validates plan secrets', function (array $secrets, string $error) {
    ApplicationFacade::plan($this->plan)->secrets()->update([['name' => 'EXISTING', 'value' => 'x']]);

    $this->actingAs($this->user)
        ->put("{$this->url}/secrets", ['secrets' => $secrets])
        ->assertSessionHasErrors($error);
})->with([
    'a name that is not an env var name' => [[['name' => 'bad name', 'value' => 'x']], 'secrets.0.name'],
    'a name starting with a digit' => [[['name' => '1KEY', 'value' => 'x']], 'secrets.0.name'],
    'a repeated name' => [[['name' => 'A_KEY', 'value' => 'x'], ['name' => 'A_KEY', 'value' => 'y']], 'secrets.0.name'],
    'names differing only by case (same Secret key)' => [[['name' => 'A_KEY', 'value' => 'x'], ['name' => 'a_key', 'value' => 'y']], 'secrets.0.name'],
    'a new secret without a value' => [[['name' => 'NEW_ONE', 'value' => '']], 'secrets.0.value'],
]);

it('adds the plan secrets to the app env, through the Secret when the driver delivers one', function () {
    ApplicationFacade::plan($this->plan)->secrets()->update([['name' => 'API_KEY', 'value' => 'abc123']]);

    $chart = chartOn($this->instance, 'kubernetes');
    $env = collect($chart->extraEnv())->firstWhere('name', 'API_KEY');

    expect($env['valueFrom']['secretKeyRef']['key'])->toBe('api-key')
        ->and(json_encode($chart->extraEnv()))->not->toContain('abc123')
        ->and(base64_decode($chart->secrets()->manifest()['data']['api-key']))->toBe('abc123');

    // Drivers that do not create the Secret keep the plain value
    expect(collect(chartOn($this->instance, 'rancher')->extraEnv())->firstWhere('name', 'API_KEY'))
        ->toBe(['name' => 'API_KEY', 'value' => 'abc123']);
});

// Secret feature settings

it('stores secret feature settings encrypted, masks them for the UI and keeps them when left blank', function () {
    $service = ApplicationFacade::plan($this->plan);
    $feature = ['status' => 'enabled', 'settings' => ['secretfeat_url' => 'https://example.test', 'secretfeat_token' => 's3cret']];

    $service->updateFeatures(['secretfeat' => $feature]);

    $stored = $this->plan->fresh()->settings['features']['secretfeat']['settings'];
    expect($stored['secretfeat_url'])->toBe('https://example.test')
        ->and($stored['secretfeat_token'])->not->toBe('s3cret')
        ->and(SecretValue::decrypt($stored['secretfeat_token']))->toBe('s3cret');

    // What the admin pages get: no value, only that one is set
    $shown = ApplicationFacade::plan($this->plan->fresh())->features()->all()['secretfeat'];
    expect($shown['settings']['secretfeat_token'])->toBe('')
        ->and($shown['secrets_set'])->toBe(['secretfeat_token' => true])
        ->and($shown['settings']['secretfeat_url'])->toBe('https://example.test');

    // The page posts the blank value back: the stored one is kept
    $service->updateFeatures(['secretfeat' => ['status' => 'enabled', 'settings' => ['secretfeat_url' => 'https://other.test', 'secretfeat_token' => '']]]);

    $stored = $this->plan->fresh()->settings['features']['secretfeat']['settings'];
    expect(SecretValue::decrypt($stored['secretfeat_token']))->toBe('s3cret')
        ->and($stored['secretfeat_url'])->toBe('https://other.test');
});

it('does not send a secret feature setting to the browser', function () {
    ApplicationFacade::plan($this->plan)->updateFeatures(['secretfeat' => ['status' => 'enabled', 'settings' => ['secretfeat_token' => 's3cret']]]);

    $content = $this->actingAs($this->user)->get("{$this->url}/features")->assertOk()->getContent();

    expect($content)->not->toContain('s3cret')
        ->not->toContain(addslashes($this->plan->fresh()->settings['features']['secretfeat']['settings']['secretfeat_token']));
});

it('delivers a secret feature setting through the Secret, not as a plain env value', function () {
    ApplicationFacade::plan($this->plan)->updateFeatures(['secretfeat' => ['status' => 'enabled', 'settings' => ['secretfeat_url' => 'https://example.test', 'secretfeat_token' => 's3cret']]]);

    $chart = chartOn($this->instance->fresh(), 'kubernetes');
    $env = collect($chart->extraEnv());

    expect($env->firstWhere('name', 'SECRETFEAT_URL'))->toBe(['name' => 'SECRETFEAT_URL', 'value' => 'https://example.test'])
        ->and($env->firstWhere('name', 'SECRETFEAT_TOKEN')['valueFrom']['secretKeyRef']['key'])->toBe('secretfeat-token')
        ->and(json_encode($env))->not->toContain('s3cret')
        ->and(base64_decode($chart->secrets()->manifest()['data']['secretfeat-token']))->toBe('s3cret');
});

it('still delivers a feature secret that was stored as plain text before it was flagged secret', function () {
    $this->plan->updateSetting('features', ['secretfeat' => ['status' => 'enabled', 'settings' => ['secretfeat_token' => 'legacy-plain']]]);

    $chart = chartOn($this->instance->fresh(), 'kubernetes');
    $chart->extraEnv();

    expect(base64_decode($chart->secrets()->manifest()['data']['secretfeat-token']))->toBe('legacy-plain');
});
