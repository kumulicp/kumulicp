<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\NucleiParser;
use App\Support\Security\SecurityToolProfile;

class NucleiTool extends SecurityToolProfile
{
    protected $name = 'nuclei';

    protected $image = 'projectdiscovery/nuclei:v3.7.0';

    protected $command = ['nuclei', '-target', '', '-json'];

    protected $parser = NucleiParser::class;
}
