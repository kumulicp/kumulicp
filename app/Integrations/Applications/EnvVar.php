<?php

namespace App\Integrations\Applications;

class EnvVar
{
    // Names, among those returned by get(), whose values are secrets. Chart::extraEnv()
    // delivers these from the chart's Secret instead of as plaintext env values.
    public function secretNames(): array
    {
        return [];
    }
}
