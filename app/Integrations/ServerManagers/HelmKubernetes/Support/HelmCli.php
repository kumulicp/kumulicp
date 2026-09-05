<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use App\Server;
use Illuminate\Support\Facades\File;
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
     * @param  string|null  $home  Override HOME for this invocation (see runWithOciLogin()).
     */
    public function run(array $subcommand, string $namespace, ?string $input = null, int $timeout = 720, ?string $home = null): array
    {
        return $this->context->withAuthArgs($namespace, function (array $helm_args) use ($subcommand, $input, $timeout, $home) {
            $binary = config('services.helm.binary_path', 'helm');
            $command = array_merge([$binary], $subcommand, $helm_args);

            $process = Process::timeout($timeout)->input($input);
            if ($home) {
                $process = $process->env(['HOME' => $home]);
            }

            $result = $process->run($command);

            return [
                'success' => $result->successful(),
                'output' => trim($result->output()),
                'error' => trim($result->errorOutput()),
                'exit_code' => $result->exitCode(),
            ];
        });
    }

    /**
     * `helm registry login` writes credentials to $HOME/.config/helm --
     * shared, stateful, host-keyed. An ephemeral HOME (deleted afterward)
     * keeps concurrent logins to different registries/credentials from
     * racing or leaking into each other, and the login itself must run
     * under the same HOME as $subcommand so the OCI pull picks it up.
     */
    public function runWithOciLogin(array $subcommand, string $namespace, ?string $input, string $registry_host, string $username, string $password, int $timeout = 720): array
    {
        $home = sys_get_temp_dir().'/kumulicp-helm-home-'.bin2hex(random_bytes(16));
        File::makeDirectory($home, 0700, true);

        try {
            $binary = config('services.helm.binary_path', 'helm');
            $login = Process::timeout(60)->env(['HOME' => $home])->input($password)
                ->run([$binary, 'registry', 'login', $registry_host, '--username', $username, '--password-stdin']);

            if (! $login->successful()) {
                return [
                    'success' => false,
                    'output' => trim($login->output()),
                    'error' => trim($login->errorOutput()),
                    'exit_code' => $login->exitCode(),
                ];
            }

            return $this->run($subcommand, $namespace, $input, $timeout, $home);
        } finally {
            File::deleteDirectory($home);
        }
    }
}
