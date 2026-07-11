<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use App\Server;
use Illuminate\Support\Facades\Process;

/**
 * Shells out to the `helm` binary, authenticating per-invocation from the
 * Server's stored credentials (see K8sCredentialContext) — never a mounted
 * kubeconfig.
 */
class HelmCli
{
    private K8sCredentialContext $context;

    public function __construct(private Server $server)
    {
        $this->context = new K8sCredentialContext($server);
    }

    /**
     * Run `helm <subcommand...>` in the given namespace.
     *
     * @param  array  $subcommand  e.g. ['upgrade', '--install', 'release-name', 'chart-name']
     */
    public function run(array $subcommand, string $namespace, ?string $input = null, int $timeout = 720): array
    {
        return $this->context->withAuthArgs($namespace, function (array $helm_args) use ($subcommand, $input, $timeout) {
            $binary = config('services.helm.binary_path', 'helm');
            $command = array_merge([$binary], $subcommand, $helm_args);

            $result = Process::timeout($timeout)->input($input)->run($command);

            return [
                'success' => $result->successful(),
                'output' => trim($result->output()),
                'error' => trim($result->errorOutput()),
                'exit_code' => $result->exitCode(),
            ];
        });
    }
}
