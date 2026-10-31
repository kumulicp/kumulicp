<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Kubernetes\API\Pod as BasePod;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;

class Pod extends BasePod
{
    use UsesRancher;
}
