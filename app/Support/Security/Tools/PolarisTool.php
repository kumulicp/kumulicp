<?php

namespace App\Support\Security\Tools;

use App\Support\Security\Parsers\PolarisParser;
use App\Support\Security\SecurityToolProfile;

class PolarisTool extends SecurityToolProfile
{
    protected $name = 'polaris';

    protected $image = 'quay.io/fairwinds/polaris:latest';

    protected $command = ['polaris', 'audit', '--format', 'json'];

    protected $parser = PolarisParser::class;
}
