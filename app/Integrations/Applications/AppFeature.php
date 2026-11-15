<?php

namespace App\Integrations\Applications;

use App\AppInstance;

class AppFeature
{
    public $name = '';

    public $label = '';

    public $description = '';

    // The env var that carries this feature's on/off state to the chart. Empty
    // for a feature that isn't driven by one, like one that only decides which
    // apps get installed on a site.
    public $var_name = '';

    // The aspect of the instance a change to this feature (in the plan or on the
    // instance) puts out of date, when it isn't the default: 'settings', or none
    // for a feature with an action, which has an aspect of its own (see
    // FeatureAspect). A feature that ends up in the chart's values and has no
    // action says 'release'.
    public $aspect = null;

    public ?AppInstance $app_instance = null;

    public function admin_settings()
    {
        return [];
    }

    // Names of the admin_settings() flagged `'secret' => true`. Those are stored
    // encrypted, never shown again in the admin UI, and handed to the app
    // through the chart's Kubernetes Secret instead of as plain env values.
    public function secretSettings(): array
    {
        return array_keys(array_filter($this->admin_settings(), fn ($setting) => $setting['secret'] ?? false));
    }
}
