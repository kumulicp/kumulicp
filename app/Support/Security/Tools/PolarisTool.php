<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\PolarisParser;
use App\Support\Security\SecurityToolProfile;

class PolarisTool extends SecurityToolProfile
{
    protected $name = 'polaris';

    protected $image = 'us-docker.pkg.dev/fairwinds-ops/oss/polaris:v10.2.0';

    protected $command = ['polaris', 'audit', '--format', 'json'];

    protected $parser = PolarisParser::class;

    /**
     * $options['namespaces']: scope the scan to a namespace instead of the
     * whole cluster. Unlike Trivy's --include-namespaces, Polaris's
     * --namespace takes a single value, not a comma-separated list - so
     * only the first selected namespace is applied.
     */
    public function command(array $targets = [], array $options = [])
    {
        $command = $this->command;

        if (! empty($options['namespaces'])) {
            $command[] = '--namespace';
            $command[] = $options['namespaces'][0];
        }

        return $command;
    }

    public function supportsNamespaceFilter(): bool
    {
        return true;
    }

    public function namespaceFilterAllowsMultiple(): bool
    {
        return false;
    }
}
