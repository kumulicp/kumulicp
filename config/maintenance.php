<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App maintenance mode
    |--------------------------------------------------------------------------
    |
    | An app in maintenance mode has its own ingress turned off, and a second
    | release (see MaintenanceChart) serves a maintenance page at the app's
    | domain instead. The page is the image from
    | https://github.com/wickerlabs/maintenance, deployed with the "generic"
    | chart from https://github.com/helmforgedev/charts.
    |
    */

    'chart' => [
        // Chart repository URL, for the kubernetes driver
        'repo' => env('MAINTENANCE_CHART_REPO', 'https://repo.helmforge.dev'),
        // Name of a ClusterRepo registered in Rancher for the same repository,
        // for the rancher driver (which installs from its own catalog)
        'rancher_repo' => env('MAINTENANCE_CHART_RANCHER_REPO'),
        'name' => env('MAINTENANCE_CHART_NAME', 'generic'),
        'version' => env('MAINTENANCE_CHART_VERSION', '3.1.5'),
    ],

    'image' => [
        'registry' => env('MAINTENANCE_IMAGE_REGISTRY', 'docker.io'),
        'repository' => env('MAINTENANCE_IMAGE_REPOSITORY', 'wickerlabs/maintenance'),
        'tag' => env('MAINTENANCE_IMAGE_TAG', 'latest'),
        // The image's PORT variable; it listens on 8080 unless told otherwise
        'port' => 8080,
    ],

    // The ingress class when the app's own configuration has none
    'ingress_class' => env('MAINTENANCE_INGRESS_CLASS', 'traefik'),

    // The address the page's "contact us" link writes to. The image's own
    // default is mail@example.com, so fall back to the platform's.
    'contact_email' => env('MAINTENANCE_CONTACT_EMAIL'),

    // Environment variables of the image (TITLE, HEADLINE, MESSAGE, THEME, ...)
    // to set on every maintenance page, over the ones worked out for the app.
    'env' => [],

];
