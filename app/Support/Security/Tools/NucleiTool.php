<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\NucleiParser;
use App\Support\Security\SecurityToolProfile;

class NucleiTool extends SecurityToolProfile
{
    protected $name = 'nuclei';

    protected $image = 'projectdiscovery/nuclei:v3.7.0';

    // -or/-omit-raw and -ot/-omit-template drop the full HTTP request/response
    // bodies and base64-encoded template source nuclei otherwise embeds in
    // every result by default - neither is used by NucleiParser, and leaving
    // them in makes the report balloon (~1.1MB of raw HTTP bodies for a
    // single-domain scan with 67 findings observed in testing).
    protected $command = ['nuclei', '-jsonl', '-or', '-ot'];

    protected $parser = NucleiParser::class;

    public function command(array $targets = [], array $options = [])
    {
        $command = $this->command;

        foreach ($targets as $target) {
            $command[] = '-target';
            $command[] = $target;
        }

        return $command;
    }

    public function requiresTargets(): bool
    {
        return true;
    }
}
