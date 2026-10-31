<?php

namespace App\Enums;

use App\ChartSource;

/**
 * Which channel decides how an application is deployed.
 */
enum ApplicationSource: string
{
    // Has a hand-written profile registered in code (built-in or from a plugin module)
    case Profile = 'profile';

    // Added by an admin in the UI, deployed with the generic chart
    case Generic = 'generic';

    // Imported from the HelmForge chart repository, deployed by HelmForgeChart
    case HelmForge = 'helmforge';

    // Each chart repository follows its own values schema, so only the ones
    // with a dedicated profile and chart class can be imported.
    public static function forChartSource(ChartSource $source): ?self
    {
        return match ($source->slug) {
            'helmforge' => self::HelmForge,
            default => null,
        };
    }
}
