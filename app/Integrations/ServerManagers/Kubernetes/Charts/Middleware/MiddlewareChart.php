<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts\Middleware;

use App\Integrations\ServerManagers\Kubernetes\Charts\Chart;

abstract class MiddlewareChart extends Chart
{
    abstract public function values(): array;
}
