<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\KubeBenchParser;
use App\Support\Security\SecurityToolProfile;

class KubeBenchTool extends SecurityToolProfile
{
    protected $name = 'kube-bench';

    protected $image = 'aquasec/kube-bench:v0.15.6';

    protected $command = ['kube-bench', 'run', '--json'];

    protected $parser = KubeBenchParser::class;
}
