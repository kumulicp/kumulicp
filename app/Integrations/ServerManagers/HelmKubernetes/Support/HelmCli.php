<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use App\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Shells out to the `helm` binary, authenticating per-invocation from the
 * Server's stored credentials (see K8sCredentialContext) — never a mounted
 * kubeconfig. Only used for read-only/Helm's-own-bookkeeping operations
 * (Application::retrieve()/isActive()) -- install/upgrade/uninstall run as
 * an in-cluster Job instead, see HelmInstaller.
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
     * @param  array  $subcommand  e.g. ['status', 'release-name', '-o', 'json']
     */
    public function run(array $subcommand, string $namespace, ?string $input = null, int $timeout = 720): array
    {
        return $this->context->withAuthArgs($namespace, function (array $helm_args) use ($subcommand, $input, $timeout) {
            $binary = config('services.helm.binary_path', 'helm');
            $command = array_merge([$binary], $subcommand, $helm_args);

            $result = Process::timeout($timeout)->input($input)->run($command);

            if (! $result->successful()) {
                // Log the verb and namespace only: the full command can contain secrets or manifests.
                Log::warning('Helm command failed', [
                    'event' => 'helm.command_failed',
                    'command' => $subcommand[0] ?? null,
                    'namespace' => $namespace,
                    'exit_code' => $result->exitCode(),
                    'error' => mb_substr(trim($result->errorOutput()), 0, 500),
                ]);
            }

            return [
                'success' => $result->successful(),
                'output' => trim($result->output()),
                'error' => trim($result->errorOutput()),
                'exit_code' => $result->exitCode(),
            ];
        });
    }
}
