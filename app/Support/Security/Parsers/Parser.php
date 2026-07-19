<?php

namespace App\Support\Security\Parsers;

interface Parser
{
    /**
     * Parse raw scanner output into a flat list of findings:
     * [['severity' => ..., 'title' => ..., 'category' => ..., 'resource_type' => ...,
     *   'resource_name' => ..., 'description' => ..., 'remediation' => ..., 'rule_id' => ...,
     *   'metadata' => [...]], ...]
     *
     * resource_type/resource_name identify what the finding is about (e.g. a
     * Kubernetes Kind/Name) where the tool provides that - leave both null
     * when it doesn't apply (e.g. Nuclei's findings are about a URL, not a
     * cluster resource). metadata is a free-form array of any other
     * tool-specific fields worth surfacing (package versions, CVSS score,
     * a canonical reference URL, etc.) - shown as-is in the finding detail
     * view rather than needing its own column.
     */
    public function parse(string $raw_output): array;
}
