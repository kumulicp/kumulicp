<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Kubernetes\API\Ingress as BaseIngress;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;

class Ingress extends BaseIngress
{
    use UsesRancher;

    protected function state(array $ingress): int
    {
        return $this->rancherState($ingress);
    }
}
