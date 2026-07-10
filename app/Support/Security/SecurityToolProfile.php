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

    public function command()
    {
        return $this->command;
    }

    public function parser(): Parser
    {
        return new $this->parser;
    }
}
