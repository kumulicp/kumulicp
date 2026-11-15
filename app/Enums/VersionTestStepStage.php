<?php

namespace App\Enums;

/**
 * The two stages a shared app is tested in: the shared app itself, and then a
 * site added to it as another organization would.
 */
enum VersionTestStepStage: string
{
    // The shared app is up; the site to try out is yet to be added
    case CORE = 'core';

    // A site has been added to the release and can be tried out
    case SITE = 'site';
}
