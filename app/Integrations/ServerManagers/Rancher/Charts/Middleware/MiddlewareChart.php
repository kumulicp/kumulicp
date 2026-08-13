<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Middleware;

use App\Integrations\ServerManagers\Rancher\Charts\Chart;

abstract class MiddlewareChart extends Chart
{
    abstract public function values(): array;
}
