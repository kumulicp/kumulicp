<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog sync
    |--------------------------------------------------------------------------
    |
    | Charts imported from a chart source (see App\ChartSource) get a generic,
    | data-driven app profile instead of a hand-written one. The settings below
    | tune how that import behaves.
    |
    */

    // How many of a chart's newest versions are imported as app versions.
    'versions_per_chart' => 3,

    // Chart names that are never imported.
    'exclude' => [
        // Library/utility charts that aren't applications
        'generic',
    ],

    /*
    | Per-chart tweaks for the few charts that don't follow the common values
    | layout. Keyed by chart name; any key of the detected convention (see
    | ChartConventionDetector) can be replaced here, e.g.:
    |
    |   'paperless-ngx' => [
    |       'persistence_volume' => 'data',
    |       'admin_password' => 'paperless.admin.password',
    |   ],
    */
    'overrides' => [
        'paperless-ngx' => [
            'persistence_volume' => 'data',
            'admin_password' => 'paperless.admin.password',
        ],
    ],
];
