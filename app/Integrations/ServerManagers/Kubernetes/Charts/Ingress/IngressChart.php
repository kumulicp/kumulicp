<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts\Ingress;

use App\Integrations\ServerManagers\Kubernetes\Charts\Chart;

abstract class IngressChart extends Chart
{
    abstract public function values(): array;
}
