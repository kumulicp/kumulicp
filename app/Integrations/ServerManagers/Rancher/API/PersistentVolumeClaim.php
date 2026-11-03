<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Kubernetes\API\PersistentVolumeClaim as BasePersistentVolumeClaim;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;

class PersistentVolumeClaim extends BasePersistentVolumeClaim
{
    use UsesRancher;

    protected function state(array $claim): int
    {
        return $this->rancherState($claim, 'bound');
    }

    // Rancher clusters use Longhorn unless the server says otherwise.
    protected function storageClass(): ?string
    {
        return parent::storageClass() ?? 'longhorn';
    }
}
