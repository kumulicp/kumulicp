<?php

namespace App\Integrations\SSO\Authentik;

use App\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Checks the LDAP source the users and groups come from, then creates disposable
// provider/application/group/policy binding records in Authentik and deletes them all
// regardless of where it failed.
class AuthentikConfigTest
{
    private array $created = [];

    public function __construct(private Server $server) {}

    public function run(): array
    {
        if (! $this->server->address || ! $this->server->api_key || ! $this->server->api_secret) {
            return [
                'success' => false,
                'message' => __('admin.servers.authentik.test_missing_credentials'),
            ];
        }

        $name = 'kumuli-config-test-'.Str::random(8);

        try {
            // Read only, so a source that is missing fails before anything is created
            $this->checkLdapSource();

            $provider = $this->createProvider($name);
            $application = $this->createApplication($name, $provider['pk']);
            $group = $this->createGroup($name);
            $this->createPolicyBinding($application['pk'], $group['pk']);

            return [
                'success' => true,
                'message' => __('admin.servers.authentik.test_success'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('admin.servers.authentik.test_failed', ['reason' => $e->getMessage()]),
            ];
        } finally {
            $this->cleanup();
        }
    }

    private function client()
    {
        return Http::withHeaders([
            'Authorization' => "{$this->server->api_key} {$this->server->api_secret}",
            'Accept' => 'application/json',
        ])->baseUrl(rtrim($this->server->address, '/'))->timeout(15);
    }

    /**
     * The LDAP source is made by hand in Authentik (docs/server-transitions-plan.md): apps
     * find their groups through it, and without it provider setup never gets any further.
     */
    private function checkLdapSource(): void
    {
        $slug = $this->server->setting('ldap_source_slug') ?? 'ldap';
        $response = $this->client()->get("/api/v3/sources/ldap/{$slug}/");

        if ($response->status() === 404) {
            throw new \RuntimeException(__('admin.servers.authentik.ldap_source_missing', ['slug' => $slug]));
        }

        $source = $this->assert($response, __('admin.servers.authentik.test_step.ldap_source'));

        if (! ($source['enabled'] ?? false)) {
            throw new \RuntimeException(__('admin.servers.authentik.ldap_source_disabled', ['slug' => $slug]));
        }

        if (! ($source['sync_groups'] ?? false)) {
            throw new \RuntimeException(__('admin.servers.authentik.ldap_source_no_groups', ['slug' => $slug]));
        }

        if (empty($source['group_property_mappings'])) {
            throw new \RuntimeException(__('admin.servers.authentik.ldap_source_no_group_mapping', ['slug' => $slug]));
        }
    }

    private function createProvider(string $name): array
    {
        $response = $this->client()->post('/api/v3/providers/oauth2/', [
            'name' => $name,
            'authentication_flow' => $this->server->setting('authentication_flow'),
            'authorization_flow' => $this->server->setting('authorization_flow'),
            'invalidation_flow' => $this->server->setting('invalidation_flow'),
            'property_mappings' => $this->server->settingArray('property_mappings'),
            'client_type' => $this->server->setting('client_type') ?? 'confidential',
            'access_code_validity' => $this->server->setting('access_code_validity'),
            'access_token_validity' => $this->server->setting('access_token_validity'),
            'refresh_token_validity' => $this->server->setting('refresh_token_validity'),
            'include_claims_in_id_token' => true,
            'signing_key' => $this->server->setting('signing_key'),
            'encryption_key' => $this->server->setting('encryption_key'),
            'redirect_uris' => [['matching_mode' => 'regex', 'url' => '.*']],
            'sub_mode' => $this->server->setting('sub_mode'),
            'issuer_mode' => $this->server->setting('issuer_mode'),
            'jwt_federation_sources' => $this->server->settingArray('jwt_federation_sources'),
            'jwt_federation_providers' => $this->server->settingArray('jwt_federation_providers'),
        ]);

        $data = $this->assert($response, __('admin.servers.authentik.test_step.provider'));
        $this->created['provider'] = $data['pk'];

        return $data;
    }

    private function createApplication(string $name, int $provider_pk): array
    {
        $response = $this->client()->post('/api/v3/core/applications/', [
            'name' => $name,
            'slug' => $name,
            'provider' => $provider_pk,
            'meta_launch_url' => 'https://example.invalid',
            'policy_engine_mode' => 'any',
        ]);

        $data = $this->assert($response, __('admin.servers.authentik.test_step.application'));
        $this->created['application'] = $data['slug'];

        return $data;
    }

    private function createGroup(string $name): array
    {
        $response = $this->client()->post('/api/v3/core/groups/', [
            'name' => $name,
        ]);

        $data = $this->assert($response, __('admin.servers.authentik.test_step.group'));
        $this->created['group'] = $data['pk'];

        return $data;
    }

    private function createPolicyBinding(string $target, string $group): array
    {
        $response = $this->client()->post('/api/v3/policies/bindings/', [
            'target' => $target,
            'group' => $group,
            'negate' => false,
            'enabled' => true,
            'order' => 0,
        ]);

        $data = $this->assert($response, __('admin.servers.authentik.test_step.policy_binding'));
        $this->created['policy_binding'] = $data['pk'];

        return $data;
    }

    private function assert($response, string $step): array
    {
        if ($response->failed()) {
            throw new \RuntimeException("{$step}: HTTP {$response->status()} — ".$response->body());
        }

        return $response->json();
    }

    private function cleanup(): void
    {
        if ($policy_binding = $this->created['policy_binding'] ?? null) {
            $this->client()->delete("/api/v3/policies/bindings/{$policy_binding}/");
        }

        if ($application = $this->created['application'] ?? null) {
            $this->client()->delete("/api/v3/core/applications/{$application}/");
        }

        if ($provider = $this->created['provider'] ?? null) {
            $this->client()->delete("/api/v3/providers/oauth2/{$provider}/");
        }

        if ($group = $this->created['group'] ?? null) {
            $this->client()->delete("/api/v3/core/groups/{$group}/");
        }
    }
}
