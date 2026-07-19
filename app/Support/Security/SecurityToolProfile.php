<?php

namespace App\Support\Security;

use App\Support\Facades\Settings;
use App\Support\Security\Parsers\Parser;

class SecurityToolProfile
{
    protected $name;

    protected $description;

    protected $image;

    protected $command = [];

    protected $parser;

    public function name()
    {
        return $this->name;
    }

    /**
     * Translation key for this tool's description. Defaults to the built-in
     * admin.security.tool_descriptions.{name} convention; override
     * $description with a custom key (e.g. a module's own translation
     * namespace) to provide a description for a tool registered elsewhere.
     */
    public function descriptionKey()
    {
        return $this->description ?: "admin.security.tool_descriptions.{$this->name}";
    }

    public function description()
    {
        return __($this->descriptionKey());
    }

    /**
     * The pinned, known-good image version shipped by default.
     */
    public function defaultImage()
    {
        return $this->image;
    }

    public function imageSettingKey()
    {
        return "security_tool_image_{$this->name}";
    }

    /**
     * The image actually used to run the scan: an admin-provided override
     * from Settings if one is set, otherwise the pinned default.
     */
    public function image()
    {
        return Settings::get($this->imageSettingKey(), $this->image) ?: $this->image;
    }

    /**
     * @param  string[]  $targets  Hosts/domains to scan, for tools that need
     *                             a per-scan target (see requiresTargets()).
     *                             Ignored by tools that scan the cluster.
     * @param  array  $options  Tool-specific scan options (see
     *                          supportsSeverityFilter()). Ignored by tools
     *                          that don't declare support for them.
     */
    public function command(array $targets = [], array $options = [])
    {
        return $this->command;
    }

    /**
     * Whether this tool needs an explicit list of hosts/domains to scan,
     * rather than scanning the cluster itself.
     */
    public function requiresTargets(): bool
    {
        return false;
    }

    /**
     * Whether this tool accepts a `severity` list and `ignore_unfixed` flag
     * in its $options to narrow a report that can otherwise get too large to
     * review (e.g. Trivy's vulnerability scan).
     */
    public function supportsSeverityFilter(): bool
    {
        return false;
    }

    /**
     * Whether this tool accepts a `namespaces` list in its $options to scope
     * a cluster-wide scan down to specific namespaces, rather than always
     * scanning everything (e.g. Trivy's vulnerability scan, whose report
     * size scales with the whole cluster's package/image count).
     */
    public function supportsNamespaceFilter(): bool
    {
        return false;
    }

    public function parser(): Parser
    {
        return new $this->parser;
    }
}
