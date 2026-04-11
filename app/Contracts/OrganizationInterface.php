<?php

namespace App\Contracts;

interface OrganizationInterface
{
    public function existsOrganization();

    public function organization();

    public function addOrganization();

    public function updateOrganization();

    public function deleteOrganization();
}
