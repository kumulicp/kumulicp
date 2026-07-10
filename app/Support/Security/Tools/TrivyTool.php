<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\TrivyParser;
use App\Support\Security\SecurityToolProfile;

class TrivyTool extends SecurityToolProfile
{
    protected $name = 'trivy';

    protected $image = 'aquasec/trivy:latest';

    protected $command = ['trivy', 'k8s', '--report', 'all', '--format', 'json', 'cluster'];

    protected $parser = TrivyParser::class;
}
