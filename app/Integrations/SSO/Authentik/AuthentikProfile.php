<?php

namespace App\Integrations\SSO\Authentik;

use App\Integrations\SSO\Authentik\Interfaces\AuthentikSSOInterface;
use Illuminate\Support\Arr;

class AuthentikProfile
{
    // Authentik 2026.2+ creates OAuth2 providers with no grant types unless they're sent, and then rejects
    // every authorize request with invalid_request. Nextcloud (user_oidc) and the other OIDC apps only
    // need the code flow, plus refresh tokens for the offline_access scope.
    public const DEFAULT_GRANT_TYPES = ['authorization_code', 'refresh_token'];

    private $interfaces = [
        'sso' => AuthentikSSOInterface::class,
    ];

    public function description()
    {
        return [
            'host' => __('admin.servers.authentik.host'),
            'address' => __('admin.servers.authentik.address'),
            'api_key' => __('admin.servers.authentik.api_key'),
            'api_secret' => __('admin.servers.authentik.api_secret'),
            'ip' => __('admin.servers.authentik.ip'),
            'internal_address' => __('admin.servers.authentik.internal_address'),
            'settings' => __('admin.servers.authentik.settings'),
            'general' => [
                __('admin.servers.authentik.general_1'),
                __('admin.servers.authentik.general_2'),
                __('admin.servers.authentik.general_3'),
            ],
        ];
    }

    public function interface(string $interface)
    {
        return Arr::get($this->interfaces, $interface, null);
    }

    public function configTest(): ?string
    {
        return AuthentikConfigTest::class;
    }

    // Instance-specific UUIDs (signing_key, invalidation_flow,
    // property_mappings, authorization_flow, authentication_flow) are left
    // out — the admin still has to look those up in Authentik.
    public function defaultSettings(): array
    {
        return [
            'sub_mode' => 'hashed_user_id',
            'client_type' => 'confidential',
            'issuer_mode' => 'global',
            'access_code_validity' => 'minutes=1',
            'access_token_validity' => 'minutes=5',
            'refresh_token_validity' => 'days=30',
            'include_claims_in_id_token' => false,
            'encryption_key' => null,
            'grant_types' => self::DEFAULT_GRANT_TYPES,
            'jwt_federation_sources' => [],
            'jwt_federation_providers' => [],
        ];
    }
}
