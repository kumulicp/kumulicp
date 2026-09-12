<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Concerns;

use App\AppInstance;

// Shared by chart classes that need to resolve which release actually owns
// this app instance. The release owner is the shared-app parent
// (AppInstance::sharedPlanParent()) for a child registered against a shared
// plan, or the instance itself for a standalone/dedicated install --
// including a shared-app parent's own activation, which is just a normal
// standalone install until other orgs register against it.
trait ResolvesHub
{
    private ?AppInstance $resolvedHub = null;

    protected function hub(): AppInstance
    {
        return $this->resolvedHub ??= ($this->app_instance->sharedPlanParent() ?? $this->app_instance);
    }
}
