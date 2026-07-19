<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\KubescapeParser;
use App\Support\Security\SecurityToolProfile;

class KubescapeTool extends SecurityToolProfile
{
    protected $name = 'kubescape';

    protected $image = 'quay.io/kubescape/kubescape-cli:v4.0.10';

    protected $command = ['kubescape', 'scan', '--format', 'json'];

    protected $parser = KubescapeParser::class;
}
