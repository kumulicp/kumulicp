<?php

namespace App\Services;

use App\Support\Security\SecurityToolProfile;
use App\Support\Security\Tools\KubeBenchTool;
use App\Support\Security\Tools\KubeHunterTool;
use App\Support\Security\Tools\KubescapeTool;
use App\Support\Security\Tools\NucleiTool;
use App\Support\Security\Tools\PolarisTool;
use App\Support\Security\Tools\TrivyTool;
use Illuminate\Support\Arr;

class SecurityToolService
{
    private $tools = [];

    public function __construct()
    {
        $this->register(new KubeHunterTool);
        $this->register(new KubeBenchTool);
        $this->register(new KubescapeTool);
        $this->register(new TrivyTool);
        $this->register(new PolarisTool);
        $this->register(new NucleiTool);
    }

    public function isRegistered(string $tool)
    {
        return array_key_exists($tool, $this->tools);
    }

    public function register(SecurityToolProfile $tool)
    {
        $name = $tool->name();

        if (! $this->isRegistered($name)) {
            $this->tools[$name] = $tool;
        }

        return $this;
    }

    public function profile(string $tool): ?SecurityToolProfile
    {
        return Arr::get($this->tools, $tool);
    }

    public function all(): array
    {
        return array_keys($this->tools);
    }
}
