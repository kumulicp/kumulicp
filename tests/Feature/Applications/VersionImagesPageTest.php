<?php

use App\AppVersion;
use App\AppVersionImage;
use App\RepoSecret;
use App\Support\Facades\Application;
use App\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;
use Tests\Support\VersionUpdates\ChartArchive;

/**
 * The images of a version: what it has, finding what its chart runs, and
 * adding it.
 */
beforeEach(function () {
    $support = new TestSupports;
    $support->seed();

    Application::register(new DemoAppProfile);
    $this->demoApp = Application::initialize('demo_app');
    Application::roles($this->demoApp);

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
});

function imagesVersion(array $settings = [], string $name = '40.0.1'): AppVersion
{
    return AppVersion::factory()->create([
        'application_id' => test()->demoApp->id,
        'name' => $name,
        'status' => 'deactivated',
        'settings' => $settings ?: null,
    ]);
}

function imagesUrl(AppVersion $version, string $path = ''): string
{
    return "/admin/apps/{$version->application->slug}/versions/{$version->id}/images".$path;
}

// A version that runs the chart "demo" 1.0.0, which is published with the values given
function chartedVersion(array $chart_values): AppVersion
{
    Http::fake([
        'charts.example.com/index.yaml' => Http::response(ChartArchive::index('demo', ['1.0.0'])),
        'charts.example.com/demo-1.0.0.tgz' => Http::response(ChartArchive::make('demo', '1.0.0', $chart_values)),
    ]);

    return imagesVersion([
        'helm_repo_name' => 'https://charts.example.com',
        'chart_name' => 'demo',
        'chart_version' => '1.0.0',
        'image_registry' => 'docker.io',
        'image_repo_name' => 'library/demo',
    ]);
}

function newImage(array $attributes = []): array
{
    return $attributes + [
        'key' => 'worker',
        'label' => 'Worker',
        'values_path' => 'worker.image',
        'registry' => 'ghcr.io',
        'repository' => 'org/worker',
        'tag' => '3.0.0',
        'digest' => null,
        'source' => 'override',
    ];
}

// ─── The page ────────────────────────────────────────────────────────────────

it('lists the images of a version, the one its settings describe first', function () {
    $version = imagesVersion(['image_registry' => 'docker.io', 'image_repo_name' => 'library/demo']);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'redis', 'label' => 'Redis', 'registry' => 'docker.io', 'repository' => 'library/redis', 'tag' => '8.0.1', 'values_path' => 'redis.image', 'position' => 1]);

    $this->withoutVite()
        ->get(imagesUrl($version))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Applications/Versions/VersionImages')
            ->where('discovered', null)
            ->where('discover_error', null)
            ->where('images', fn ($images) => collect($images)->pluck('key')->all() === ['primary', 'redis']
                && collect($images)->first()['reference'] === 'docker.io/library/demo:40.0.1'
                && collect($images)->first()['follows_settings'] === true
                && collect($images)->last()['reference'] === 'docker.io/library/redis:8.0.1'
                && collect($images)->last()['follows_settings'] === false
                && collect($images)->last()['source'] === 'override'
                && collect($images)->last()['values_path'] === 'redis.image')
        );
});

// ─── Finding what the chart runs ─────────────────────────────────────────────

it('proposes the images the version\'s chart runs, and which of them the version has already', function () {
    $version = chartedVersion([
        'image' => ['registry' => 'docker.io', 'repository' => 'library/demo', 'tag' => '40.0.1'],
        'worker' => ['image' => ['registry' => 'ghcr.io', 'repository' => 'org/worker', 'tag' => '3.0.0']],
        'proxy' => ['image' => 'ghcr.io/org/proxy:1.2.3'],
    ]);

    $this->withoutVite()
        ->get(imagesUrl($version, '/discover'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Applications/Versions/VersionImages')
            ->where('discover_error', null)
            ->where('discovered', function ($discovered) {
                $rows = collect($discovered)->keyBy('key');

                return $rows->keys()->all() === ['primary', 'worker', 'proxy']
                    // The one the version's settings describe
                    && $rows['primary']['exists'] === true
                    && $rows['primary']['values_path'] === 'image'
                    && $rows['worker']['exists'] === false
                    && $rows['worker']['overridable'] === true
                    && $rows['worker']['label'] === 'Worker'
                    && $rows['worker']['values_path'] === 'worker.image'
                    && $rows['worker']['registry'] === 'ghcr.io'
                    && $rows['worker']['repository'] === 'org/worker'
                    && $rows['worker']['tag'] === '3.0.0'
                    // Only recorded, as it isn't a block of registry, repository and tag
                    && $rows['proxy']['overridable'] === false
                    && $rows['proxy']['tag'] === '1.2.3';
            })
        );
});

it('keeps the key of an image the version already has for a place in the values', function () {
    $version = chartedVersion(['worker' => ['image' => ['repository' => 'org/worker', 'tag' => '3.0.0']]]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'background', 'label' => 'Background', 'values_path' => 'worker.image']);

    $this->withoutVite()
        ->get(imagesUrl($version, '/discover'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discovered', fn ($discovered) => collect($discovered)->contains(fn ($row) => $row['key'] === 'background'
                && $row['label'] === 'Background'
                && $row['exists'] === true))
        );
});

it('gives each image a key of its own', function () {
    $version = chartedVersion([
        'a' => ['image' => ['repository' => 'org/one', 'tag' => '1']],
        'b' => ['image' => ['repository' => 'org/two', 'tag' => '1']],
        'image' => ['repository' => 'org/three', 'tag' => '1'],
        'x' => ['image' => 'ghcr.io/org/four:1'],
    ]);
    // "primary" and "a" are taken
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'a', 'values_path' => 'elsewhere.image']);

    $this->withoutVite()
        ->get(imagesUrl($version, '/discover'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discovered', fn ($discovered) => collect($discovered)->pluck('key')->all() === ['a-2', 'b', 'primary', 'x'])
        );
});

it('says why the chart could not be read', function () {
    $version = imagesVersion(['helm_repo_name' => 'https://charts.example.com', 'chart_name' => 'demo', 'chart_version' => '1.0.0']);

    Http::fake(['charts.example.com/*' => Http::response('', 404)]);

    $this->withoutVite()
        ->get(imagesUrl($version, '/discover'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discovered', null)
            ->where('discover_error', fn ($error) => str_contains($error, 'could not be read'))
        );
});

it('says when the version has no chart to look in', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);

    Http::fake();

    $this->withoutVite()
        ->get(imagesUrl($version, '/discover'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discovered', null)
            ->where('discover_error', fn ($error) => str_contains($error, 'no chart'))
        );

    Http::assertNothingSent();
});

it('finds nothing in a chart that runs no images', function () {
    $version = chartedVersion(['replicaCount' => 1]);

    $this->withoutVite()
        ->get(imagesUrl($version, '/discover'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('discovered', []));
});

// ─── Adding them ─────────────────────────────────────────────────────────────

it('adds the images picked from the chart, the ones Kumulicp sets and the chart\'s own', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);

    $this->post(imagesUrl($version), ['images' => [
        newImage(),
        newImage(['key' => 'proxy', 'label' => 'Proxy', 'values_path' => 'proxy.image', 'repository' => 'org/proxy', 'tag' => '1.2.3', 'source' => 'chart']),
        // Listed by the chart, with no place in its values
        newImage(['key' => 'helper', 'label' => 'helper', 'values_path' => null, 'registry' => null, 'repository' => 'org/helper', 'tag' => null, 'source' => 'chart']),
    ]])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect(imagesUrl($version))
        ->assertSessionHas('success');

    $images = $version->fresh()->images->keyBy('key');

    expect($images->keys()->all())->toBe(['primary', 'worker', 'proxy', 'helper'])
        ->and($images['worker']->only(['role', 'source', 'registry', 'repository', 'tag', 'values_path', 'update_policy', 'position']))->toBe([
            'role' => 'component', 'source' => 'override', 'registry' => 'ghcr.io', 'repository' => 'org/worker', 'tag' => '3.0.0', 'values_path' => 'worker.image', 'update_policy' => 'track', 'position' => 1,
        ])
        ->and($images['worker']->label)->toBe('Worker')
        // What the chart runs isn't checked for updates: the chart brings them
        ->and($images['proxy']->source)->toBe('chart')
        ->and($images['proxy']->update_policy)->toBe('ignore')
        ->and($images['proxy']->position)->toBe(2)
        ->and($images['helper']->values_path)->toBe('')
        ->and($images['helper']->tag)->toBeNull();
});

it('writes an image that was added to the chart\'s values, and not one that is only recorded', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);

    $this->post(imagesUrl($version), ['images' => [
        newImage(),
        newImage(['key' => 'proxy', 'values_path' => 'proxy.image', 'repository' => 'org/proxy', 'source' => 'chart']),
    ]])->assertSessionDoesntHaveErrors();

    expect($version->fresh()->extraImages()->pluck('key')->all())->toBe(['worker']);
});

it('makes the version\'s tests stale when an image is added to it', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);
    $fingerprint = (new App\Support\VersionTesting\VersionFingerprint)->for($version);

    $this->post(imagesUrl($version), ['images' => [newImage(['source' => 'chart'])]])->assertSessionDoesntHaveErrors();

    expect((new App\Support\VersionTesting\VersionFingerprint)->for($version->fresh()))->not->toBe($fingerprint);
});

it('only adds an image Kumulicp sets when it says where and with what', function (array $change) {
    $version = imagesVersion();

    $this->post(imagesUrl($version), ['images' => [newImage($change)]])
        ->assertSessionHasErrors('images.0.source');

    expect($version->fresh()->images)->toHaveCount(0);
})->with([
    'no place in the values' => [['values_path' => null]],
    'no tag or digest' => [['tag' => null, 'digest' => null]],
]);

it('takes an image that sets a digest and no tag', function () {
    $version = imagesVersion();

    $this->post(imagesUrl($version), ['images' => [newImage(['tag' => null, 'digest' => 'sha256:abc123'])]])
        ->assertSessionDoesntHaveErrors();

    expect($version->fresh()->images->first()->digest)->toBe('sha256:abc123');
});

it('does not add a key the version has, or one sent twice', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);

    $this->post(imagesUrl($version), ['images' => [newImage(['key' => 'primary'])]])
        ->assertSessionHasErrors('images.0.key');

    $this->post(imagesUrl($version), ['images' => [newImage(), newImage(['values_path' => 'other.image'])]])
        ->assertSessionHasErrors('images.1.key');

    expect($version->fresh()->images)->toHaveCount(1);
});

it('does not add a key that is not a slug, or an image without a repository', function () {
    $version = imagesVersion();

    $this->post(imagesUrl($version), ['images' => [newImage(['key' => 'Not Valid', 'repository' => ''])]])
        ->assertSessionHasErrors(['images.0.key', 'images.0.repository']);

    $this->post(imagesUrl($version), ['images' => [newImage(['source' => 'whatever'])]])
        ->assertSessionHasErrors('images.0.source');

    $this->post(imagesUrl($version), ['images' => []])->assertSessionHasErrors('images');

    expect($version->fresh()->images)->toHaveCount(0);
});

// ─── Removing one ────────────────────────────────────────────────────────────

it('removes an image of the version', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);
    $image = AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'redis', 'label' => 'Redis']);

    $this->delete(imagesUrl($version, '/'.$image->id))
        ->assertRedirect(imagesUrl($version))
        ->assertSessionHas('success');

    expect($version->fresh()->images->pluck('key')->all())->toBe(['primary']);
});

it('keeps the image the version\'s name and settings describe', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);
    $primary = $version->primaryImage();

    $this->delete(imagesUrl($version, '/'.$primary->id))->assertSessionHas('error');

    expect($version->fresh()->primaryImage())->not->toBeNull();
});

it('does not remove the image of another version', function () {
    $version = imagesVersion();
    $other = imagesVersion([], '40.0.2');
    $image = AppVersionImage::factory()->create(['app_version_id' => $other->id, 'key' => 'redis']);

    $this->delete(imagesUrl($version, '/'.$image->id))->assertNotFound();

    expect($other->fresh()->images)->toHaveCount(1);
});

// ─── Editing one ─────────────────────────────────────────────────────────────

function editedImage(array $attributes = []): array
{
    return $attributes + [
        'label' => 'Background worker',
        'registry' => 'quay.io',
        'repository' => 'org/worker',
        'tag' => '4.0.0',
        'digest' => null,
        'registry_secret_id' => null,
        'values_path' => 'worker.image',
        'source' => 'override',
        'update_policy' => 'minor',
        'tag_pattern' => '^\d+\.\d+\.\d+$',
        'group' => 'workers',
    ];
}

function componentImage(AppVersion $version, array $attributes = []): AppVersionImage
{
    return AppVersionImage::factory()->create($attributes + ['app_version_id' => $version->id, 'key' => 'worker', 'values_path' => 'worker.image']);
}

it('shows what can be edited of each image, and what it can be given', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);
    $secret = RepoSecret::factory()->create(['name' => 'Quay', 'type' => RepoSecret::TYPE_IMAGE]);
    RepoSecret::factory()->create(['type' => RepoSecret::TYPE_HELM]);
    componentImage($version, ['registry_secret_id' => $secret->id, 'update_policy' => 'patch', 'tag_pattern' => '^1', 'group' => 'workers']);

    $this->withoutVite()
        ->get(imagesUrl($version))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('update_policies', ['track', 'minor', 'patch', 'pinned', 'ignore'])
            ->where('image_registries', fn ($registries) => collect($registries)->pluck('name')->all() === ['Quay'])
            ->where('images', fn ($images) => collect($images)->last()['registry_secret_id'] === $secret->id
                && collect($images)->last()['update_policy'] === 'patch'
                && collect($images)->last()['tag_pattern'] === '^1'
                && collect($images)->last()['group'] === 'workers'
                && collect($images)->last()['repository'] !== null)
        );
});

it('changes an image of the version', function () {
    $version = imagesVersion(['image_repo_name' => 'library/demo']);
    $image = componentImage($version);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage())
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect(imagesUrl($version))
        ->assertSessionHas('success');

    expect($image->fresh()->only(['label', 'registry', 'repository', 'tag', 'values_path', 'source', 'update_policy', 'tag_pattern', 'group']))->toBe([
        'label' => 'Background worker', 'registry' => 'quay.io', 'repository' => 'org/worker', 'tag' => '4.0.0', 'values_path' => 'worker.image',
        'source' => 'override', 'update_policy' => 'minor', 'tag_pattern' => '^\d+\.\d+\.\d+$', 'group' => 'workers',
    ])->and($image->fresh()->key)->toBe('worker')->and($image->fresh()->role)->toBe('component');
});

it('gives an image a registry login of the right kind, or none', function () {
    $version = imagesVersion();
    $image = componentImage($version);
    $login = RepoSecret::factory()->create(['type' => RepoSecret::TYPE_BOTH]);
    $helm = RepoSecret::factory()->create(['type' => RepoSecret::TYPE_HELM]);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage(['registry_secret_id' => $helm->id]))->assertSessionHasErrors('registry_secret_id');
    $this->put(imagesUrl($version, '/'.$image->id), editedImage(['registry_secret_id' => $login->id]))->assertSessionDoesntHaveErrors();
    expect($image->fresh()->registry_secret_id)->toBe($login->id);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage(['registry_secret_id' => null]))->assertSessionDoesntHaveErrors();
    expect($image->fresh()->registry_secret_id)->toBeNull();
});

it('keeps an image the chart only lists without a place in the values', function () {
    $version = imagesVersion();
    $image = componentImage($version, ['source' => 'chart']);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage(['source' => 'chart', 'values_path' => null, 'tag' => null]))
        ->assertSessionDoesntHaveErrors();

    expect($image->fresh()->values_path)->toBe('')
        ->and($image->fresh()->tag)->toBeNull();
});

it('only lets an image Kumulicp sets say where and with what', function (array $change) {
    $version = imagesVersion();
    $image = componentImage($version);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage($change))->assertSessionHasErrors('source');

    expect($image->fresh()->repository)->not->toBe('org/worker');
})->with([
    'no place in the values' => [['values_path' => null]],
    'no tag or digest' => [['tag' => null, 'digest' => null]],
]);

it('does not accept what is not a policy, a pattern or a group', function (array $change, string $field) {
    $version = imagesVersion();
    $image = componentImage($version);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage($change))->assertSessionHasErrors($field);
})->with([
    'a policy that does not exist' => [['update_policy' => 'sometimes'], 'update_policy'],
    'a pattern that is not a regular expression' => [['tag_pattern' => '('], 'tag_pattern'],
    'a group that is not a slug' => [['group' => 'Not A Slug'], 'group'],
    'no repository' => [['repository' => ''], 'repository'],
    'a source that does not exist' => [['source' => 'whatever'], 'source'],
]);

it('only changes how the image its settings describe is checked for updates', function () {
    $version = imagesVersion(['image_registry' => 'docker.io', 'image_repo_name' => 'library/demo']);
    $primary = $version->primaryImage();

    $this->put(imagesUrl($version, '/'.$primary->id), [
        'label' => 'The app',
        'update_policy' => 'patch',
        'tag_pattern' => '^\d+',
        'group' => 'main',
        // Where it comes from is the version's settings
        'repository' => 'someone/else',
        'tag' => '0.0.1',
        'source' => 'chart',
    ])->assertSessionDoesntHaveErrors();

    $primary = $version->fresh()->primaryImage();

    expect($primary->only(['label', 'update_policy', 'tag_pattern', 'group']))->toBe(['label' => 'The app', 'update_policy' => 'patch', 'tag_pattern' => '^\d+', 'group' => 'main'])
        ->and($primary->repository)->toBe('library/demo')
        ->and($primary->effectiveTag())->toBe('40.0.1')
        ->and($primary->source)->toBe('override');
});

it('does not change the image of another version', function () {
    $version = imagesVersion();
    $other = imagesVersion([], '40.0.2');
    $image = componentImage($other);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage())->assertNotFound();

    expect($image->fresh()->repository)->not->toBe('org/worker');
});

it('makes the version\'s tests stale when what an image runs is edited, but not how it is checked', function () {
    $version = imagesVersion();
    $image = componentImage($version);
    $fingerprint = fn () => (new App\Support\VersionTesting\VersionFingerprint)->for($version->fresh());
    $before = $fingerprint();

    $this->put(imagesUrl($version, '/'.$image->id), editedImage(['repository' => $image->repository, 'registry' => $image->registry, 'tag' => $image->tag, 'values_path' => $image->values_path]))
        ->assertSessionDoesntHaveErrors();
    expect($fingerprint())->toBe($before);

    $this->put(imagesUrl($version, '/'.$image->id), editedImage(['repository' => $image->repository, 'registry' => $image->registry, 'tag' => '9.9.9', 'values_path' => $image->values_path]))
        ->assertSessionDoesntHaveErrors();
    expect($fingerprint())->not->toBe($before);
});
