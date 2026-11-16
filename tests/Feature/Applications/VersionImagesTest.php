<?php

use App\Application;
use App\AppVersion;
use App\AppVersionImage;
use App\RepoSecret;
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
