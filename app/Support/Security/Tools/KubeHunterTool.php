<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\KubeHunterParser;
use App\Support\Security\SecurityToolProfile;

class KubeHunterTool extends SecurityToolProfile
{
    protected $name = 'kube-hunter';

    protected $image = 'aquasec/kube-hunter:0.6.8';

    protected $command = ['kube-hunter', '--pod', '--report', 'json'];

    protected $parser = KubeHunterParser::class;
}
