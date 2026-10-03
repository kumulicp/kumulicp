<?php

namespace App\Integrations\ServerManagers\Rancher\Services;

trait OrganizationServices
{
    public function existsOrganization()
    {
        return $this->namespace->isActive() === 1;
    }

    public function organization() {}

    public function addOrganization()
    {
        return $this->namespace->create();
    }

    // Reconciles the namespace's Pod Security labels with its plan's tier.
    // A no-op unless the server's security_mode is `managed`.
    public function updateOrganization()
    {
        return $this->namespace->update();
    }

    public function deleteOrganization()
    {
        return $this->namespace->remove();
    }
}
