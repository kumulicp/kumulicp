<?php

namespace App\Support\Security;

use App\Support\Facades\Settings;
use App\Support\Security\Parsers\Parser;

class SecurityToolProfile
{
    protected $name;

    protected $image;

    protected $command = [];

    protected $parser;

    public function name()
    {
        return $this->name;
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
