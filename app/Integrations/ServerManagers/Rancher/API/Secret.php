<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Kubernetes\API\Secret as BaseSecret;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;

class Secret extends BaseSecret
{
    use UsesRancher;
}
