<?php

namespace App\Sso;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

class OidcProvider extends AbstractProvider
{
    protected array $discovery = [];

    protected array $jwks = [];

    protected $scopeSeparator = ' ';

    /**
     * Default scopes
     */
    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * Get authorization URL
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(
            $this->getDiscovery()['authorization_endpoint'],
            $state
        );
    }

    /**
     * Get token endpoint
     */
    protected function getTokenUrl()
    {
        return $this->getDiscovery()['token_endpoint'];
    }

    /**
     * Get user by token (userinfo endpoint)
     */
    protected function getUserByToken($token)
    {
        $discovery = $this->getDiscovery();

        if (! isset($discovery['userinfo_endpoint'])) {
            return [];
        }

        $response = Http::withToken($token)
            ->get($discovery['userinfo_endpoint']);

        return $response->successful()
            ? $response->json()
            : [];
    }

    /**
     * Map user to Socialite User object
     */
    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => $user['preferred_username'] ?? $user['nickname'] ?? null,
            'name' => $user['name']
                ?? trim(($user['given_name'] ?? '').' '.($user['family_name'] ?? '')),
            'email' => $user['email'] ?? null,
            'avatar' => $user['picture'] ?? null,
        ]);
    }

    /**
     * Override user() to merge ID token + userinfo
     */
    public function user()
    {
        $response = $this->getAccessTokenResponse($this->getCode());

        $idToken = $response['id_token'] ?? null;
        $accessToken = $response['access_token'] ?? null;

        $claims = [];

        if ($idToken) {
            $claims = $this->decodeIdToken($idToken);
        }

        $userinfo = $accessToken
            ? $this->getUserByToken($accessToken)
            : [];

        $user = array_merge($claims, $userinfo);

        return $this->mapUserToObject($user)
            ->setToken($accessToken)
            ->setRefreshToken($response['refresh_token'] ?? null)
            ->setExpiresIn($response['expires_in'] ?? null);
    }

    public static function additionalConfigKeys(): array
    {
        return ['base_url'];
    }

    /**
     * Load OIDC discovery document
     */
    protected function getDiscovery(): array
    {
        if (! empty($this->discovery)) {
            return $this->discovery;
        }
        $base = rtrim($this->getConfig('base_url') ?? '', '/');

        if (! $base) {
            throw new \InvalidArgumentException('OIDC base_uri is required');
        }

        $response = Http::get($base.'/.well-known/openid-configuration');

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch OIDC discovery document');
        }

        return $this->discovery = $response->json();
    }

    /**
     * Decode ID Token using JWKS
     */
    protected function decodeIdToken(string $idToken): array
    {
        $header = json_decode(
            base64_decode(explode('.', $idToken)[0]),
            true
        );

        $kid = $header['kid'] ?? null;

        $keys = $this->getJwks();

        if (! isset($keys[$kid])) {
            throw new \Exception('Unable to find matching JWK');
        }

        $decoded = JWT::decode($idToken, $keys);

        $this->validateIdToken($decoded);

        return (array) $decoded;
    }

    /**
     * Fetch and cache JWKS keys
     */
    protected function getJwks(): array
    {
        if (! empty($this->jwks)) {
            return $this->jwks;
        }

        $jwksUri = $this->getDiscovery()['jwks_uri'];

        $response = Http::get($jwksUri);

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch JWKS');
        }

        $keys = [];

        foreach ($response->json()['keys'] as $jwk) {
            $keys[$jwk['kid']] = new Key(
                $this->jwkToPem($jwk),
                $jwk['alg'] ?? 'RS256'
            );
        }

        return $this->jwks = $keys;
    }

    /**
     * Validate ID Token claims
     */
    protected function validateIdToken($decoded): void
    {
        $config = $this->config;

        // Validate issuer
        if (isset($this->getDiscovery()['issuer']) && $decoded->iss !== $this->getDiscovery()['issuer']) {
            throw new \Exception('Invalid issuer');
        }

        // Validate audience
        if ($decoded->aud !== $config['client_id']) {
            throw new \Exception('Invalid audience');
        }

        // Validate expiration
        if (isset($decoded->exp) && $decoded->exp < time()) {
            throw new \Exception('Token expired');
        }
    }

    /**
     * Convert JWK to PEM
     */
    protected function jwkToPem(array $jwk): string
    {
        $modulus = base64_decode(strtr($jwk['n'], '-_', '+/'));
        $exponent = base64_decode(strtr($jwk['e'], '-_', '+/'));

        $components = [
            'modulus' => $modulus,
            'publicExponent' => $exponent,
        ];

        return $this->rsaToPem($components);
    }

    protected function rsaToPem($components): string
    {
        $modulus = pack('Ca*a*', 2, $this->encodeLength(strlen($components['modulus'])), $components['modulus']);
        $publicExponent = pack('Ca*a*', 2, $this->encodeLength(strlen($components['publicExponent'])), $components['publicExponent']);

        $rsaPublicKey = pack(
            'Ca*a*a*',
            48,
            $this->encodeLength(strlen($modulus) + strlen($publicExponent)),
            $modulus,
            $publicExponent
        );

        $rsaOID = pack('H*', '300d06092a864886f70d0101010500');
        $rsaPublicKey = chr(0).$rsaPublicKey;

        $rsaPublicKey = pack(
            'Ca*a*',
            3,
            $this->encodeLength(strlen($rsaPublicKey)),
            $rsaPublicKey
        );

        $der = pack(
            'Ca*a*a*',
            48,
            $this->encodeLength(strlen($rsaOID) + strlen($rsaPublicKey)),
            $rsaOID,
            $rsaPublicKey
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    protected function encodeLength($length)
    {
        if ($length <= 0x7F) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), chr(0));

        return chr(0x80 | strlen($temp)).$temp;
    }
}
