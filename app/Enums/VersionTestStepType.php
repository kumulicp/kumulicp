<?php

namespace App\Enums;

enum VersionTestStepType: string
{
    case FRESH_INSTALL = 'fresh_install';

    // Install the currently enabled version, then upgrade to the tested one
    case UPGRADE = 'upgrade';
}
