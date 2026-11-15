<?php

namespace App\Integrations\ServerManagers\Kubernetes\Services;

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

    public function updateOrganization()
    {
        return null;
    }

    public function deleteOrganization()
    {
        return $this->namespace->remove();
    }
}
