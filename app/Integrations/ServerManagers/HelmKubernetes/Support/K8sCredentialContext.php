<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use App\Server;
use Illuminate\Support\Facades\Http;

/**
 * Builds the HTTP client used to talk to a Server's Kubernetes API, from its
 * stored connection details, without ever mounting a persistent kubeconfig.
 *
 * The helm_k8s driver reuses the Server model's existing generic fields
 * rather than dedicated columns:
 *   - address        => Kubernetes API server URL
 *   - ca_cert         => cluster CA certificate (PEM, not secret)
 *   - settings        => 'k8s_auth_type' ('bearer_token'|'client_cert'),
 *                        'k8s_tls_verify' ('true'|'false', default true),
 *                        'k8s_impersonate_user', 'k8s_impersonate_group'
 *   - api_key/api_secret (already encrypted+hidden) => bearer token
 *     (api_secret only) or client key/cert (api_key/api_secret) depending
 *     on k8s_auth_type — see HelmKubernetesProfile::description().
 *
 * Bearer-token auth is just an Authorization header. The CA certificate and
 * the client certificate/key (client_cert auth) are the only things that
 * need to exist as files, because libcurl takes file paths for them.
 *
 * Any ephemeral file is written with 0600 permissions under the system temp
 * directory, scoped to a single invocation, and always deleted afterward —
 * even if the callback throws.
 */
class K8sCredentialContext
{
    public function __construct(private Server $server) {}

    public function authType(): string
    {
        return $this->server->setting('k8s_auth_type') ?? 'bearer_token';
    }

    public function usesClientCert(): bool
    {
        return $this->authType() === 'client_cert';
    }

    public function tlsVerify(): bool
    {
        $value = $this->server->setting('k8s_tls_verify');

        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Runs $callback(PendingRequest $http) with a client pointed at the API
     * server and authenticated for this server. Returns the callback's
     * return value.
     */
    public function withRequest(callable $callback, int $timeout = 30)
    {
        return $this->withCredentialFiles(function (array $options, array $headers) use ($callback, $timeout) {
            $http = Http::baseUrl(rtrim((string) $this->server->address, '/'))
                ->acceptJson()
                ->timeout($timeout)
                ->withHeaders($headers)
                ->withOptions($options);

            if (! $this->usesClientCert()) {
                $http->withToken((string) $this->server->api_secret);
            }

            return $callback($http);
        });
    }

    /**
     * Runs $callback(array $guzzleOptions, array $headers) having written
     * whatever ephemeral file(s) the server's TLS/auth settings need.
     */
    public function withCredentialFiles(callable $callback)
    {
        $paths = [];

        try {
            $options = ['verify' => $this->tlsVerify()];

            if ($this->tlsVerify() && $this->server->ca_cert) {
                $paths['ca'] = $this->writeEphemeralFile($this->server->ca_cert);
                $options['verify'] = $paths['ca'];
            }

            if ($this->usesClientCert()) {
                // client-cert auth: api_secret holds the client
                // certificate, api_key holds the client private key.
                $paths['cert'] = $this->writeEphemeralFile((string) $this->server->api_secret);
                $paths['key'] = $this->writeEphemeralFile((string) $this->server->api_key);
                $options['cert'] = $paths['cert'];
                $options['ssl_key'] = $paths['key'];
            }

            return $callback($options, $this->impersonationHeaders());
        } finally {
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function impersonationHeaders(): array
    {
        return array_filter([
            'Impersonate-User' => $this->server->setting('k8s_impersonate_user'),
            'Impersonate-Group' => $this->server->setting('k8s_impersonate_group'),
        ]);
    }

    private function writeEphemeralFile(string $contents): string
    {
        $path = sys_get_temp_dir().'/kumulicp-k8s-'.bin2hex(random_bytes(16));

        touch($path);
        chmod($path, 0600);
        file_put_contents($path, $contents);

        return $path;
    }
}
