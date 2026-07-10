<?php

namespace App\Support\Security\Parsers;

interface Parser
{
    /**
     * Parse raw scanner output into a flat list of findings:
     * [['severity' => ..., 'title' => ..., 'category' => ..., 'description' => ..., 'remediation' => ..., 'rule_id' => ...], ...]
     */
    public function parse(string $raw_output): array;
}
