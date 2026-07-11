<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use App\Server;
use Illuminate\Support\Facades\Process;

/**
 * Shells out to the `kubectl` binary for generic Kubernetes resource
 * management (namespaces, ingresses, jobs, secrets, PVCs, pods) — the
 * K8s-native replacement for Rancher's Steve API proxy. Authenticates
 * per-invocation from the Server's stored credentials, same as HelmCli.
 */
class KubectlCli
{
    private K8sCredentialContext $context;

    public function __construct(private Server $server)
    {
        $this->context = new K8sCredentialContext($server);
    }

    /**
     * Run `kubectl <subcommand...>` in the given namespace.
     */
    public function run(array $subcommand, string $namespace, ?string $input = null, int $timeout = 120): array
    {
        return $this->context->withAuthArgs($namespace, function (array $helm_args, array $kubectl_args) use ($subcommand, $input, $timeout) {
            $binary = config('services.kubectl.binary_path', 'kubectl');
            $command = array_merge([$binary], $subcommand, $kubectl_args);

            $result = Process::timeout($timeout)->input($input)->run($command);

            return [
                'success' => $result->successful(),
                'output' => trim($result->output()),
                'error' => trim($result->errorOutput()),
                'exit_code' => $result->exitCode(),
            ];
        });
    }

    /**
     * Apply a single manifest array via `kubectl apply -f -` (stdin), the
     * generic equivalent of Rancher's `POST /v1/...` for a K8s resource.
     */
    public function apply(array $manifest, string $namespace): array
    {
        return $this->run(['apply', '-f', '-', '-o', 'json'], $namespace, json_encode($manifest));
    }

    public function get(string $kind, string $name, string $namespace): array
    {
        return $this->run(['get', $kind, $name, '-o', 'json'], $namespace);
    }

    public function delete(string $kind, string $name, string $namespace): array
    {
        return $this->run(['delete', $kind, $name, '--ignore-not-found=true', '-o', 'json'], $namespace);
    }
}
