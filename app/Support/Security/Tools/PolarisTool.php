<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\PolarisParser;
use App\Support\Security\SecurityToolProfile;

class PolarisTool extends SecurityToolProfile
{
    protected $name = 'polaris';

    protected $image = 'us-docker.pkg.dev/fairwinds-ops/oss/polaris:v10.2.0';

    protected $command = ['polaris', 'audit', '--format', 'json'];

    protected $parser = PolarisParser::class;
}
