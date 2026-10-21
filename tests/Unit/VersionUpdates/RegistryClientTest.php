<?php

use App\RepoSecret;
use App\Support\VersionUpdates\RegistryClient;
use Illuminate\Support\Facades\Http;

it('maps Docker Hub names to its API host and library namespace', function () {
    expect(RegistryClient::apiHost('docker.io'))->toBe('registry-1.docker.io')
        ->and(RegistryClient::apiHost(null))->toBe('registry-1.docker.io')
        ->and(RegistryClient::apiHost('https://ghcr.io/'))->toBe('ghcr.io')
        ->and(RegistryClient::repository('registry-1.docker.io', 'nextcloud'))->toBe('library/nextcloud')
        ->and(RegistryClient::repository('ghcr.io', 'nextcloud'))->toBe('nextcloud');
});

it('follows the bearer token challenge and tag pagination', function () {
    Http::fake([
        'auth.docker.io/*' => Http::response(['token' => 'abc']),
        'registry-1.docker.io/v2/library/nextcloud/tags/list?n=1000' => Http::sequence()
            ->push('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:library/nextcloud:pull"'])
            ->push(['tags' => ['33.0.1', '33.0.2']], 200, ['Link' => '</v2/library/nextcloud/tags/list?last=33.0.2&n=1000>; rel="next"']),
        'registry-1.docker.io/v2/library/nextcloud/tags/list?last=33.0.2&n=1000' => Http::response(['tags' => ['34.0.0']]),
    ]);

    $tags = (new RegistryClient)->tags('registry-1.docker.io', 'library/nextcloud');

    expect($tags)->toBe(['33.0.1', '33.0.2', '34.0.0']);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://auth.docker.io/token')
        && $request['scope'] === 'repository:library/nextcloud:pull');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer abc'));
});

it('uses the repo secret to get a token for private images', function () {
    $secret = RepoSecret::factory()->create(['username' => 'robot', 'password' => 'secret']);

    Http::fake([
        'ghcr.io/token*' => Http::response(['token' => 'private']),
        'ghcr.io/v2/*' => Http::sequence()
            ->push('', 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io"'])
            ->push(['tags' => ['1.0.0']]),
    ]);

    expect((new RegistryClient($secret))->tags('ghcr.io', 'org/app'))->toBe(['1.0.0']);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://ghcr.io/token')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('robot:secret')));
});
