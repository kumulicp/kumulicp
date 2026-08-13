<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Ingress;

use App\Integrations\ServerManagers\Rancher\Charts\Chart;

abstract class IngressChart extends Chart
{
    abstract public function values(): array;
}
