<?php

use App\AppInstance;
use App\Application;
use App\AppVersion;
use App\AppVersionImage;
use App\RepoSecret;
use App\Updates\ShadowedImages;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->application = Application::factory()->create();
});

function imageVersion(Application $application, array $settings = [], array $attributes = []): AppVersion
{
    return AppVersion::factory()->create(array_merge([
        'application_id' => $application->id,
        'name' => '33.0.1',
        'settings' => array_merge([
            'image_repo_name' => 'library/nextcloud',
            'image_registry' => 'docker.io',
        ], $settings),
    ], $attributes));
}

it('gives a version with an image a primary image made from its settings', function () {
    $version = imageVersion($this->application);

    $image = $version->primaryImage();

    expect($image)->not->toBeNull()
        ->and($image->key)->toBe('primary')
        ->and($image->role)->toBe('primary')
        ->and($image->source)->toBe('override')
        ->and($image->repository)->toBe('library/nextcloud')
        ->and($image->registry)->toBe('docker.io')
        ->and($image->tag_from_version_name)->toBeTrue()
        ->and($image->effectiveTag())->toBe('33.0.1')
        ->and($image->reference())->toBe('docker.io/library/nextcloud:33.0.1');
});

it('gives a version without an image no images', function () {
    $version = AppVersion::factory()->create(['application_id' => $this->application->id]);

    expect($version->images)->toHaveCount(0)
        ->and($version->primaryImage())->toBeNull();
});

it('keeps the primary image in step with the version name and settings', function () {
    $version = imageVersion($this->application);
    $secret = RepoSecret::factory()->create(['registry' => 'ghcr.io']);

    $version->name = '33.0.2';
    $version->image_registry_id = $secret->id;
    $version->settings = array_merge($version->settings, ['image_repo_name' => 'org/nextcloud']);
    $version->save();

    $version = $version->fresh();
    $image = $version->primaryImage();

    expect($version->images)->toHaveCount(1)
        ->and($image->repository)->toBe('org/nextcloud')
        ->and($image->registry_secret_id)->toBe($secret->id)
        ->and($image->effectiveTag())->toBe('33.0.2')
        ->and($image->reference())->toBe('ghcr.io/org/nextcloud:33.0.2');
});

it('removes the primary image when the version no longer has an image', function () {
    $version = imageVersion($this->application);

    $version->settings = ['chart_version' => '1.1.1'];
    $version->save();

    expect($version->fresh()->images)->toHaveCount(0);
});

it('creates a primary image for a version that gains an image later', function () {
    $version = AppVersion::factory()->create(['application_id' => $this->application->id, 'name' => '1.0']);

    expect($version->images)->toHaveCount(0);

    $version->updateSettings(['image_repo_name' => 'library/redis']);

    expect($version->fresh()->primaryImage()?->repository)->toBe('library/redis');
});

it('leaves a primary image that is edited as an image alone', function () {
    $version = imageVersion($this->application);
    $version->primaryImage()->update(['tag_from_version_name' => false, 'tag' => '9.9.9', 'repository' => 'custom/image']);

    $version->name = '33.0.2';
    $version->save();

    $image = $version->fresh()->primaryImage();

    expect($image->repository)->toBe('custom/image')
        ->and($image->effectiveTag())->toBe('9.9.9');
});

it('lists the primary image first, then the others by position', function () {
    $version = imageVersion($this->application);

    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'redis', 'position' => 2]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'imaginary', 'position' => 1]);

    expect($version->fresh()->images->pluck('key')->all())->toBe(['primary', 'imaginary', 'redis']);
});

it('keeps image keys unique within a version but not across versions', function () {
    $first = imageVersion($this->application);
    $second = imageVersion($this->application, [], ['name' => '33.0.2']);

    AppVersionImage::factory()->create(['app_version_id' => $first->id, 'key' => 'redis']);
    AppVersionImage::factory()->create(['app_version_id' => $second->id, 'key' => 'redis']);

    expect(fn () => AppVersionImage::factory()->create(['app_version_id' => $first->id, 'key' => 'redis']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('deletes a version\'s images with it', function () {
    $version = imageVersion($this->application);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'redis']);

    $version->delete();

    expect(AppVersionImage::count())->toBe(0);
});

it('keeps an image when its registry secret is deleted', function () {
    $secret = RepoSecret::factory()->create();
    $version = imageVersion($this->application);
    $image = AppVersionImage::factory()->create([
        'app_version_id' => $version->id,
        'key' => 'redis',
        'registry_secret_id' => $secret->id,
    ]);

    $secret->delete();

    expect($image->fresh()->registry_secret_id)->toBeNull();
});

it('pulls from the registry secret\'s registry before the image\'s own', function () {
    $secret = RepoSecret::factory()->create(['registry' => 'registry.example.com', 'username' => 'bot', 'password' => 'pw']);
    $version = imageVersion($this->application, ['image_registry' => 'docker.io']);
    $image = AppVersionImage::factory()->create([
        'app_version_id' => $version->id,
        'key' => 'synapse',
        'registry' => 'docker.io',
        'repository' => 'element/synapse',
        'tag' => '1.2.3',
        'registry_secret_id' => $secret->id,
    ]);

    expect($image->effectiveRegistry())->toBe('registry.example.com')
        ->and($image->requiresAuth())->toBeTrue()
        ->and($image->reference())->toBe('registry.example.com/element/synapse:1.2.3');
});

it('includes the digest in the reference', function () {
    $version = imageVersion($this->application);
    $image = AppVersionImage::factory()->create([
        'app_version_id' => $version->id,
        'key' => 'synapse',
        'registry' => 'ghcr.io',
        'repository' => 'element-hq/synapse',
        'tag' => 'v1.2.3',
        'digest' => 'sha256:abc123',
    ]);

    expect($image->reference())->toBe('ghcr.io/element-hq/synapse:v1.2.3@sha256:abc123');
});

it('backfills a primary image for versions that existed before the table', function () {
    $secret = RepoSecret::factory()->create();

    // Written straight to the table, as versions were before any of this existed
    $with_image = DB::table('app_versions')->insertGetId([
        'application_id' => $this->application->id,
        'name' => '28.0.0',
        'status' => 'active',
        'roles' => '[]',
        'settings' => json_encode(['image_repo_name' => 'library/nextcloud', 'image_registry' => 'docker.io']),
        'image_registry_id' => $secret->id,
    ]);
    $without_image = DB::table('app_versions')->insertGetId([
        'application_id' => $this->application->id,
        'name' => '1.0',
        'status' => 'deactivated',
        'roles' => '[]',
        'settings' => json_encode(['chart_version' => '1.1.1']),
    ]);
    $blank_image = DB::table('app_versions')->insertGetId([
        'application_id' => $this->application->id,
        'name' => '2.0',
        'status' => 'deactivated',
        'roles' => '[]',
        'settings' => json_encode(['image_repo_name' => ' ']),
    ]);
    $no_settings = DB::table('app_versions')->insertGetId([
        'application_id' => $this->application->id,
        'name' => '3.0',
        'status' => 'deactivated',
        'roles' => '[]',
        'settings' => null,
    ]);

    AppVersionImage::query()->delete();

    $migration = require base_path('database/migrations/2026_11_16_000000_create_app_version_images_table.php');
    $backfill = new ReflectionMethod($migration, 'backfill');
    $backfill->invoke($migration);

    $images = AppVersionImage::all();

    expect($images)->toHaveCount(1);

    $image = $images->first();

    expect($image->app_version_id)->toBe($with_image)
        ->and($image->key)->toBe('primary')
        ->and($image->role)->toBe('primary')
        ->and($image->repository)->toBe('library/nextcloud')
        ->and($image->registry)->toBe('docker.io')
        ->and($image->tag)->toBe('28.0.0')
        ->and($image->registry_secret_id)->toBe($secret->id)
        ->and($image->tag_from_version_name)->toBeTrue()
        ->and($image->values_path)->toBe('image')
        ->and($image->update_policy)->toBe('track');

    expect(AppVersionImage::where('app_version_id', $without_image)->exists())->toBeFalse()
        ->and(AppVersionImage::where('app_version_id', $blank_image)->exists())->toBeFalse()
        ->and(AppVersionImage::where('app_version_id', $no_settings)->exists())->toBeFalse();
});

// ─── What a chart is given ───────────────────────────────────────────────────

it('writes only the components the version overrides, not its primary image, the chart\'s own or jobs\'', function () {
    $version = imageVersion($this->application);

    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'component']);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'shipped', 'source' => 'chart']);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'migrate', 'role' => 'job']);

    expect($version->fresh()->extraImages()->pluck('key')->all())->toBe(['component']);
});

it('lists each registry login the version needs once, with its own first', function () {
    $own = RepoSecret::factory()->create(['registry' => 'ghcr.io', 'username' => 'bot', 'password' => 'pw']);
    $other = RepoSecret::factory()->create(['registry' => 'quay.io', 'username' => 'bot', 'password' => 'pw']);
    $anonymous = RepoSecret::factory()->create(['registry' => 'docker.io']);

    $version = imageVersion($this->application, [], ['image_registry_id' => $own->id]);

    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'one', 'registry_secret_id' => $other->id, 'position' => 1]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'two', 'registry_secret_id' => $own->id, 'position' => 2]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'three', 'registry_secret_id' => $anonymous->id, 'position' => 3]);

    expect($version->fresh()->imageRegistrySecrets()->pluck('id')->all())->toBe([$own->id, $other->id]);
});

it('gives a copy of a version its images, apart from the primary one', function () {
    $source = imageVersion($this->application);
    AppVersionImage::factory()->create(['app_version_id' => $source->id, 'key' => 'redis', 'repository' => 'library/redis', 'tag' => '8.0.1', 'position' => 1]);

    $copy = AppVersion::factory()->create(['application_id' => $this->application->id, 'name' => '33.0.2']);
    $copy->copyImagesFrom($source);

    $images = $copy->fresh()->images;

    expect($images->pluck('key')->all())->toBe(['redis'])
        ->and($images->first()->repository)->toBe('library/redis')
        ->and($images->first()->tag)->toBe('8.0.1')
        ->and($images->first()->app_version_id)->toBe($copy->id)
        // The source keeps its own
        ->and($source->fresh()->images->pluck('key')->all())->toBe(['primary', 'redis']);
});

it('adds the images a profile recommends, once', function () {
    $version = AppVersion::factory()->create(['application_id' => $this->application->id]);
    $recommendations = ['images' => [
        ['key' => 'imaginary', 'repository' => 'nextcloud/aio-imaginary', 'registry' => 'docker.io', 'tag' => '20261109_101109', 'values_path' => 'imaginary.image'],
        ['key' => 'redis', 'repository' => 'library/redis', 'tag' => '8.0.1', 'values_path' => 'redis.image'],
    ]];

    $version->addRecommendedImages($recommendations);
    $version->addRecommendedImages($recommendations);

    $images = $version->fresh()->images;

    expect($images->pluck('key')->all())->toBe(['imaginary', 'redis'])
        ->and($images->first()->role)->toBe('component')
        ->and($images->first()->source)->toBe('override')
        ->and($images->pluck('position')->all())->toBe([1, 2]);
});

it('adds nothing when a profile recommends no other images', function () {
    $version = AppVersion::factory()->create(['application_id' => $this->application->id]);

    $version->addRecommendedImages(['image_repo' => 'library/nextcloud']);

    expect($version->fresh()->images)->toHaveCount(0);
});

// ─── Registry secrets ────────────────────────────────────────────────────────

it('counts the versions that use a registry secret through one of their images', function () {
    $secret = RepoSecret::factory()->create();
    $used = imageVersion($this->application);
    imageVersion($this->application, [], ['name' => '33.0.2']);

    AppVersionImage::factory()->create(['app_version_id' => $used->id, 'key' => 'redis', 'registry_secret_id' => $secret->id]);

    expect($secret->versions()->pluck('id')->all())->toBe([$used->id]);
});

// ─── Nextcloud's imaginary image ─────────────────────────────────────────────

it('gives every existing Nextcloud version its imaginary image, once, and no other app', function () {
    $nextcloud = Application::factory()->create(['slug' => 'nextcloud']);
    $first = AppVersion::factory()->create(['application_id' => $nextcloud->id, 'name' => '33.0.1']);
    $second = AppVersion::factory()->create(['application_id' => $nextcloud->id, 'name' => '33.0.2']);
    $other = AppVersion::factory()->create(['application_id' => $this->application->id]);

    $migration = require base_path('database/migrations/2026_11_16_000001_add_imaginary_image_to_nextcloud_versions.php');
    $migration->up();
    $migration->up();

    $image = $first->fresh()->images->firstWhere('key', 'imaginary');

    expect($image->role)->toBe('component')
        ->and($image->source)->toBe('override')
        ->and($image->registry)->toBe('docker.io')
        ->and($image->repository)->toBe('nextcloud/aio-imaginary')
        ->and($image->tag)->toBe('20261109_101109')
        ->and($image->values_path)->toBe('imaginary.image')
        ->and($image->tag_from_version_name)->toBeFalse()
        ->and($second->fresh()->images->where('key', 'imaginary'))->toHaveCount(1)
        ->and($other->fresh()->images)->toHaveCount(0)
        ->and(AppVersionImage::where('key', 'imaginary')->count())->toBe(2);
});

it('recommends the imaginary image the migration gave existing versions', function () {
    $recommended = (new App\Integrations\Applications\Nextcloud\NextcloudProfile)->recommendations()['images'][0];

    expect($recommended)->toMatchArray([
        'key' => 'imaginary',
        'registry' => 'docker.io',
        'repository' => 'nextcloud/aio-imaginary',
        'tag' => '20261109_101109',
        'values_path' => 'imaginary.image',
    ]);
});

// ─── Update check results ────────────────────────────────────────────────────

function checkedImageVersion(Application $application): AppVersion
{
    $version = imageVersion($application, [], ['update_check' => [
        'checked_at' => '2026-10-14T03:00:00+00:00',
        'image' => ['current' => '33.0.1', 'latest' => '33.0.2', 'error' => null],
        'images' => [
            'primary' => ['current' => '33.0.1', 'latest' => '33.0.2', 'error' => null],
            'imaginary' => ['current' => '20261109_101109', 'latest' => '20270101_000000', 'error' => null],
        ],
        'chart' => null,
    ]]);

    AppVersionImage::factory()->create([
        'app_version_id' => $version->id,
        'key' => 'imaginary',
        'tag' => '20261109_101109',
    ]);

    return $version->fresh();
}

it('lists the other images with a newer tag than they run with, never the primary one', function () {
    $version = checkedImageVersion($this->application);

    expect($version->newerImageTags())->toBe([
        ['key' => 'imaginary', 'label' => 'imaginary', 'current' => '20261109_101109', 'latest' => '20270101_000000', 'group' => null],
    ])->and($version->newerImageTag())->toBe('33.0.2');
});

it('stops listing an image once it runs the tag that was found', function () {
    $version = checkedImageVersion($this->application);

    $version->images()->where('key', 'imaginary')->update(['tag' => '20270101_000000']);

    expect($version->fresh()->newerImageTags())->toBe([]);
});

it('has nothing to list from a check made before images were checked', function () {
    $version = imageVersion($this->application, [], ['update_check' => [
        'checked_at' => '2026-10-14T03:00:00+00:00',
        'image' => ['current' => '33.0.1', 'latest' => '33.0.2', 'error' => null],
        'chart' => null,
    ]]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'imaginary']);

    $summary = $version->fresh()->updateSummary();

    expect($summary['images'])->toBe([])
        ->and($summary['images_errors'])->toBe([])
        ->and($summary['image'])->toBe('33.0.2');
});

// ─── The images of the newer chart ───────────────────────────────────────────

function chartCheckedVersion(Application $application, string $chart_version, array $chart): AppVersion
{
    return imageVersion($application, ['chart_version' => $chart_version], ['update_check' => [
        'checked_at' => '2026-10-14T03:00:00+00:00',
        'image' => null,
        'images' => [],
        'chart' => $chart + ['current' => $chart_version, 'error' => null],
    ]]);
}

function chartCompatibility(string $version, string $status, array $image_changes): array
{
    return ['version' => $version, 'status' => $status, 'issues' => [], 'warnings' => [], 'targets' => [], 'image_changes' => $image_changes];
}

it('says how the images change in the newer chart that is on offer', function () {
    $version = chartCheckedVersion($this->application, '9.0.0', [
        'latest' => '9.1.0',
        'latest_compatible' => '9.1.0',
        'compatibility' => chartCompatibility('9.1.0', 'compatible', ['Adds the image worker.image (org/worker:3.0).']),
    ]);

    expect($version->fresh()->updateSummary()['chart_image_changes'])->toBe(['Adds the image worker.image (org/worker:3.0).']);
});

it('does not say how the images change in a chart that is not the one on offer', function () {
    // The newest chart failed its checks; the one before it is offered instead
    $version = chartCheckedVersion($this->application, '9.0.0', [
        'latest' => '9.1.0',
        'latest_compatible' => '9.0.1',
        'compatibility' => chartCompatibility('9.1.0', 'incompatible', ['Adds the image worker.image (org/worker:3.0).']),
    ]);

    expect($version->fresh()->updateSummary()['chart_image_changes'])->toBe([]);
});

it('does not say how the images change in a chart the version has caught up with', function () {
    $version = chartCheckedVersion($this->application, '9.1.0', [
        'latest' => '9.1.0',
        'latest_compatible' => '9.1.0',
        'compatibility' => chartCompatibility('9.1.0', 'compatible', ['Adds the image worker.image (org/worker:3.0).']),
    ]);

    expect($version->fresh()->updateSummary()['chart_image_changes'])->toBe([]);
});

it('has no image changes to say for a check made before they were looked for', function () {
    $version = chartCheckedVersion($this->application, '9.0.0', [
        'latest' => '9.1.0',
        'latest_compatible' => '9.1.0',
        'compatibility' => ['version' => '9.1.0', 'status' => 'compatible', 'issues' => [], 'warnings' => [], 'targets' => []],
    ]);

    expect($version->fresh()->updateSummary()['chart_image_changes'])->toBe([]);
});

// ─── Adopting an update for the picked images ────────────────────────────────

// A version whose update check found newer tags for the images given (key => [current, latest, group, digest])
function versionWithUpdates(Application $application, array $images): AppVersion
{
    $results = [];

    foreach ($images as $key => $image) {
        $results[$key] = ['current' => $image['current'], 'latest' => $image['latest'], 'policy' => 'track', 'error' => null] + (isset($image['latest_digest']) ? ['digest' => $image['latest_digest']] : []);
    }

    $version = imageVersion($application, [], ['update_check' => ['checked_at' => '2026-10-14T03:00:00+00:00', 'image' => null, 'images' => $results, 'chart' => null]]);

    foreach ($images as $key => $image) {
        AppVersionImage::factory()->create([
            'app_version_id' => $version->id,
            'key' => $key,
            'tag' => $image['current'],
            'digest' => $image['digest'] ?? null,
            'group' => $image['group'] ?? null,
        ]);
    }

    return $version->fresh();
}

function copyOf(AppVersion $source): AppVersion
{
    $copy = AppVersion::factory()->create(['application_id' => $source->application_id, 'name' => '41.0.0']);
    $copy->copyImagesFrom($source);

    return $copy->fresh();
}

it('moves only the picked images of a copy to their newer tag', function () {
    $source = versionWithUpdates($this->application, [
        'imaginary' => ['current' => '1.0.0', 'latest' => '1.1.0'],
        'redis' => ['current' => '8.0.1', 'latest' => '8.0.2'],
    ]);
    $copy = copyOf($source);

    expect($copy->adoptImageUpdates($source, ['redis']))->toBe(['redis']);

    $tags = $copy->fresh()->images->pluck('tag', 'key');

    expect($tags['redis'])->toBe('8.0.2')
        ->and($tags['imaginary'])->toBe('1.0.0')
        // The version it was copied from is left as it was
        ->and($source->fresh()->images->pluck('tag', 'key')['redis'])->toBe('8.0.1');
});

it('moves the images in the same group as a picked one with it', function () {
    $source = versionWithUpdates($this->application, [
        'synapse' => ['current' => '1.0.0', 'latest' => '1.1.0', 'group' => 'matrix'],
        'admin' => ['current' => '2.0.0', 'latest' => '2.1.0', 'group' => 'matrix'],
        'redis' => ['current' => '8.0.1', 'latest' => '8.0.2'],
    ]);
    $copy = copyOf($source);

    expect($copy->adoptImageUpdates($source, ['admin']))->toBe(['synapse', 'admin']);

    $tags = $copy->fresh()->images->pluck('tag', 'key');

    expect($tags['synapse'])->toBe('1.1.0')
        ->and($tags['admin'])->toBe('2.1.0')
        ->and($tags['redis'])->toBe('8.0.1');
});

it('moves an image kept by digest to the digest of its new tag', function () {
    $source = versionWithUpdates($this->application, [
        'synapse' => ['current' => '1.0.0', 'latest' => '1.1.0', 'digest' => 'sha256:old', 'latest_digest' => 'sha256:new'],
    ]);
    $copy = copyOf($source);

    $copy->adoptImageUpdates($source, ['synapse']);

    $image = $copy->fresh()->images->first();

    expect($image->tag)->toBe('1.1.0')
        ->and($image->digest)->toBe('sha256:new');
});

it('does not move what was not picked, what has no newer tag, or what is not there', function () {
    $source = versionWithUpdates($this->application, [
        'imaginary' => ['current' => '1.0.0', 'latest' => '1.1.0'],
        'current' => ['current' => '2.0.0', 'latest' => '2.0.0'],
    ]);
    $copy = copyOf($source);

    expect($copy->adoptImageUpdates($source, []))->toBe([])
        ->and($copy->adoptImageUpdates($source, ['current', 'unknown']))->toBe([]);

    expect($copy->fresh()->images->pluck('tag', 'key')->all())->toBe(['imaginary' => '1.0.0', 'current' => '2.0.0']);
});

// ─── Settings of an instance that the version's images take the place of ─────

function instanceWithSettings(AppVersion $version, array $settings): AppInstance
{
    return (new AppInstance)->forceFill(['version_id' => $version->id, 'settings' => $settings]);
}

function shadowedBy(AppVersion $version, array $settings): array
{
    return (new ShadowedImages)->for(instanceWithSettings($version->fresh(), $settings));
}

it('lists the settings of an instance for an image its version sets that say something else', function () {
    $version = imageVersion($this->application);
    addPathImage($version, 'imaginary', 'Imaginary', 'imaginary.image', 'nextcloud/aio-imaginary', '20270101_000000');

    $shadowed = shadowedBy($version, ['override' => ['imaginary-image-tag' => '20261109_101109'], 'configurations' => ['imaginary-image-repository' => 'someone/else']]);

    expect($shadowed)->toBe([
        ['image' => 'Imaginary', 'setting' => 'imaginary-image-repository', 'value' => 'someone/else', 'used' => 'nextcloud/aio-imaginary'],
        ['image' => 'Imaginary', 'setting' => 'imaginary-image-tag', 'value' => '20261109_101109', 'used' => '20270101_000000'],
    ]);
});

it('does not list a setting that says what the version does, or that is not about its images', function () {
    $version = imageVersion($this->application);
    addPathImage($version, 'imaginary', 'Imaginary', 'imaginary.image', 'nextcloud/aio-imaginary', '20270101_000000');

    expect(shadowedBy($version, ['override' => ['imaginary-image-tag' => '20270101_000000', 'imaginary-image-registry' => '', 'imaginary-replicaCount' => 2]]))->toBe([])
        ->and(shadowedBy($version, ['override' => ['imaginary-image-tag' => ['not' => 'a tag']]]))->toBe([])
        ->and(shadowedBy($version, []))->toBe([])
        ->and(shadowedBy($version, ['configurations' => null]))->toBe([]);
});

it('lists nothing for images the version does not set itself', function () {
    $version = imageVersion($this->application);
    addPathImage($version, 'shipped', 'Shipped', 'shipped.image', 'org/shipped', '1.0.0', ['source' => 'chart']);
    addPathImage($version, 'migrate', 'Migrate', 'migrate.image', 'org/migrate', '1.0.0', ['role' => 'job']);

    expect(shadowedBy($version, ['override' => ['shipped-image-tag' => '0.1', 'migrate-image-tag' => '0.1', 'image-tag' => '0.1']]))->toBe([]);
});

it('names a part of an image after the place it is set at, whatever that place is called', function () {
    $version = imageVersion($this->application);
    addPathImage($version, 'worker', '', 'matrix.worker.image', 'org/worker', '2.0.0');

    expect(shadowedBy($version, ['configurations' => ['matrix-worker-image-tag' => '1.0.0']]))->toBe([
        ['image' => 'worker', 'setting' => 'matrix-worker-image-tag', 'value' => '1.0.0', 'used' => '2.0.0'],
    ]);
});

function addPathImage(AppVersion $version, string $key, string $label, string $path, string $repository, string $tag, array $attributes = []): AppVersionImage
{
    return AppVersionImage::factory()->create($attributes + [
        'app_version_id' => $version->id,
        'key' => $key,
        'label' => $label ?: null,
        'registry' => 'docker.io',
        'repository' => $repository,
        'tag' => $tag,
        'values_path' => $path,
    ]);
}
