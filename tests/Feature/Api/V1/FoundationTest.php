<?php

use App\Organization;
use App\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);

    // Throwaway routes that exercise each error path through the real
    // "api.v1" middleware group.
    Route::middleware('api.v1')->prefix('api/v1/_test')->group(function () {
        Route::post('validate', fn (Request $request) => $request->validate(['name' => ['required']]));
        Route::get('forbidden', fn () => abort(403, 'Not allowed.'));
        Route::get('boom', fn () => throw new RuntimeException('secret internal detail'));
        Route::get('missing-model', fn () => Task::findOrFail(999999));
    });
});

describe('ping', function () {
    it('answers without authentication', function () {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'version' => 'v1']);
    });

    it('returns JSON even when the client sends no Accept header', function () {
        $this->get('/api/v1/ping', ['Accept' => ''])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    });
});

describe('request id', function () {
    it('generates one when the client sends none', function () {
        $id = $this->getJson('/api/v1/ping')->headers->get('X-Request-Id');

        expect($id)->toBeString()->and(Str::isUuid($id))->toBeTrue();
    });

    it('echoes a well-formed client id', function () {
        $this->getJson('/api/v1/ping', ['X-Request-Id' => 'client-req-12345'])
            ->assertHeader('X-Request-Id', 'client-req-12345');
    });

    it('replaces a malformed client id', function () {
        $id = $this->getJson('/api/v1/ping', ['X-Request-Id' => 'bad id!'])->headers->get('X-Request-Id');

        expect($id)->not->toBe('bad id!')->and(Str::isUuid($id))->toBeTrue();
    });

    it('matches the id in the error body', function () {
        $response = $this->getJson('/api/v1/_test/forbidden', ['X-Request-Id' => 'client-req-12345']);

        $response->assertHeader('X-Request-Id', 'client-req-12345')
            ->assertJsonPath('request_id', 'client-req-12345');
    });
});

describe('error format', function () {
    it('renders validation failures with field errors', function () {
        $this->postJson('/api/v1/_test/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['message', 'code', 'request_id', 'errors' => ['name']]);
    });

    it('renders validation failures as JSON even without an Accept header', function () {
        $this->post('/api/v1/_test/validate', [], ['Accept' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    });

    it('renders denied requests as forbidden with the denial message', function () {
        $this->getJson('/api/v1/_test/forbidden')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('message', 'Not allowed.');
    });

    it('renders unknown routes as not found', function () {
        $this->getJson('/api/v1/nope')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertHeader('X-Request-Id');
    });

    it('renders unknown routes outside v1 as JSON too', function () {
        $this->get('/api/nope')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('renders a wrong HTTP method as method not allowed', function () {
        $this->postJson('/api/v1/ping')
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    });

    it('does not leak model details on a missing record', function () {
        $response = $this->getJson('/api/v1/_test/missing-model')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'Resource not found.');

        expect($response->getContent())->not->toContain('App\\Task');
    });

    it('hides exception details on a server error', function () {
        $response = $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('code', 'server_error')
            ->assertJsonPath('message', 'Server error.');

        expect($response->getContent())->not->toContain('secret internal detail');
    });

    it('shows exception details on a server error in debug mode', function () {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('message', 'secret internal detail');
    });

    it('leaves web error pages alone', function () {
        $this->get('/definitely-not-a-page')
            ->assertNotFound()
            ->assertHeaderMissing('X-Request-Id');
    });
});

describe('rate limiting', function () {
    it('returns 429 with Retry-After once the limit is exceeded', function () {
        config(['api.rate_limit' => 2]);

        $this->getJson('/api/v1/ping')->assertOk();
        $this->getJson('/api/v1/ping')->assertOk();

        $this->getJson('/api/v1/ping')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');
    });
});

describe('legacy endpoints', function () {
    it('rejects unauthenticated requests with JSON 401 instead of a login redirect', function () {
        $this->get('/api/users')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });

    it('still serves authenticated requests on the original path', function () {
        $organization = Organization::factory()->create();

        $this->actingAs($organization, 'api')
            ->getJson('/api/users')
            ->assertOk();
    });
});
