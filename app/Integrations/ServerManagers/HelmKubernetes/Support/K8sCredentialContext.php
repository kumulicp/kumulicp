<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use App\Server;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds helm/kubectl authentication CLI args from a Server's stored
 * connection details, without ever mounting a persistent kubeconfig file.
 *
 * Bearer-token auth is passed entirely as CLI flags (only the CA cert, which
 * is not secret, needs an ephemeral file — helm/kubectl require a file path
 * for it). Client-certificate auth has no CLI-flag equivalent in helm/kubectl,
 * so a full kubeconfig is generated inline (client cert/key embedded as
 * base64 `-data` fields) and written to a single ephemeral file.
 *
 * Any ephemeral file is written with 0600 permissions under the system temp
 * directory, scoped to a single invocation, and always deleted afterward —
 * even if the callback throws.
 */
class K8sCredentialContext
{
    public function __construct(private Server $server) {}

    public function needsKubeconfig(): bool
    {
        return $this->server->k8s_auth_type === 'client_cert';
    }

    /**
     * Runs $callback(array $helmArgs, array $kubectlArgs) with the CLI auth
     * args for this server, having written whatever ephemeral file(s) are
     * required. Returns the callback's return value.
     */
    public function withAuthArgs(string $namespace, callable $callback)
    {
        $paths = [];

        try {
            if ($this->needsKubeconfig()) {
                $paths['kubeconfig'] = $this->writeEphemeralFile($this->buildKubeconfig($namespace));

                $helm_args = ['--kubeconfig', $paths['kubeconfig'], '--kube-context', 'kumulicp', '--namespace', $namespace];
                $kubectl_args = ['--kubeconfig', $paths['kubeconfig'], '--context', 'kumulicp', '--namespace', $namespace];
            } else {
                if ($this->server->k8s_ca_cert) {
                    $paths['ca'] = $this->writeEphemeralFile($this->server->k8s_ca_cert);
                }

                $helm_args = $this->bearerTokenArgs('helm', $paths['ca'] ?? null, $namespace);
                $kubectl_args = $this->bearerTokenArgs('kubectl', $paths['ca'] ?? null, $namespace);
            }

            return $callback($helm_args, $kubectl_args);
        } finally {
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function bearerTokenArgs(string $tool, ?string $ca_path, string $namespace): array
    {
        $verify_flag = $this->server->k8s_tls_verify ? 'false' : 'true';

        if ($tool === 'helm') {
            $args = [
                '--kube-apiserver', $this->server->k8s_api_server,
                '--kube-token', $this->server->k8s_bearer_token,
                '--kube-insecure-skip-tls-verify='.$verify_flag,
                '--namespace', $namespace,
            ];

            if ($ca_path) {
                array_push($args, '--kube-ca-file', $ca_path);
            }

            if ($this->server->k8s_impersonate_user) {
                array_push($args, '--kube-as-user', $this->server->k8s_impersonate_user);
            }

            if ($this->server->k8s_impersonate_group) {
                array_push($args, '--kube-as-group', $this->server->k8s_impersonate_group);
            }

            return $args;
        }

        $args = [
            '--server', $this->server->k8s_api_server,
            '--token', $this->server->k8s_bearer_token,
            '--insecure-skip-tls-verify='.$verify_flag,
            '--namespace', $namespace,
        ];

        if ($ca_path) {
            array_push($args, '--certificate-authority', $ca_path);
        }

        if ($this->server->k8s_impersonate_user) {
            array_push($args, '--as', $this->server->k8s_impersonate_user);
        }

        if ($this->server->k8s_impersonate_group) {
            array_push($args, '--as-group', $this->server->k8s_impersonate_group);
        }

        return $args;
    }

    private function buildKubeconfig(string $namespace): string
    {
        $cluster = array_filter([
            'server' => $this->server->k8s_api_server,
            'certificate-authority-data' => $this->server->k8s_ca_cert ? base64_encode($this->server->k8s_ca_cert) : null,
            'insecure-skip-tls-verify' => $this->server->k8s_tls_verify ? null : true,
        ], fn ($value) => $value !== null);

        $config = [
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'clusters' => [[
                'name' => 'kumulicp',
                'cluster' => $cluster,
            ]],
            'users' => [[
                'name' => 'kumulicp',
                'user' => [
                    'client-certificate-data' => base64_encode((string) $this->server->k8s_client_cert),
                    'client-key-data' => base64_encode((string) $this->server->k8s_client_key),
                ],
            ]],
            'contexts' => [[
                'name' => 'kumulicp',
                'context' => [
                    'cluster' => 'kumulicp',
                    'user' => 'kumulicp',
                    'namespace' => $namespace,
                ],
            ]],
            'current-context' => 'kumulicp',
        ];

        return Yaml::dump($config, 6);
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
