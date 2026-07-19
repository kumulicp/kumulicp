<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\TrivyParser;
use App\Support\Security\SecurityToolProfile;

class TrivyTool extends SecurityToolProfile
{
    protected $name = 'trivy';

    protected $image = 'aquasec/trivy:0.72.0';

    // No positional [CONTEXT] arg: passing one (even a placeholder like
    // "cluster") makes trivy look for that literal kubectl context and fail
    // with "context does not exist". Omitting it makes trivy scan the whole
    // cluster using the pod's own in-cluster ServiceAccount, same as the
    // other tools.
    // --disable-node-collector: without it, trivy deploys its own job onto
    // every node (in a namespace it creates itself, "trivy-temp" by default)
    // to gather OS-level config. That needs create/manage permissions well
    // beyond the read-only get/list/watch model the scan ServiceAccount is
    // otherwise scoped to, and kube-bench already covers node/kubelet CIS
    // checks - so it's disabled rather than widening the RBAC footprint.
    // --timeout: trivy's default is 5m, which a full cluster scan (config
    // audit + image vulnerability scanning across every workload) can easily
    // exceed. The Job itself has no activeDeadlineSeconds, so there's nothing
    // else cutting this short - only trivy's own internal timeout.
    protected $command = ['trivy', 'k8s', '--report', 'all', '--format', 'json', '--disable-node-collector', '--timeout', '30m'];

    protected $parser = TrivyParser::class;

    public const SEVERITIES = ['UNKNOWN', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /**
     * $options['severity']: subset of self::SEVERITIES to report (trivy
     * reports all of them by default - a full, unfiltered cluster scan can
     * run into tens of megabytes of vulnerability data).
     * $options['ignore_unfixed']: drop vulnerabilities with no available fix.
     * $options['namespaces']: scope the scan to specific namespaces instead
     * of the whole cluster - report size scales with the cluster's total
     * package/image count, and severity/ignore-unfixed filtering alone isn't
     * always enough to keep it under Kubernetes' pod log rotation limit.
     */
    public function command(array $targets = [], array $options = [])
    {
        $command = $this->command;

        $severity = array_values(array_intersect($options['severity'] ?? [], self::SEVERITIES));
        if (! empty($severity)) {
            $command[] = '--severity';
            $command[] = implode(',', $severity);
        }

        if (! empty($options['ignore_unfixed'])) {
            $command[] = '--ignore-unfixed';
        }

        if (! empty($options['namespaces'])) {
            $command[] = '--include-namespaces';
            $command[] = implode(',', $options['namespaces']);
        }

        return $command;
    }

    public function supportsSeverityFilter(): bool
    {
        return true;
    }

    public function supportsNamespaceFilter(): bool
    {
        return true;
    }
}
