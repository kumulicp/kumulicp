<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Kubernetes\API\Middleware as BaseMiddleware;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;

class Middleware extends BaseMiddleware
{
    use UsesRancher;

    // Rancher clusters run Traefik, so no server setting is needed to opt in.
    public function enabled(): bool
    {
        return true;
    }

    protected function state(array $middleware): int
    {
        return $this->rancherState($middleware);
    }
}
