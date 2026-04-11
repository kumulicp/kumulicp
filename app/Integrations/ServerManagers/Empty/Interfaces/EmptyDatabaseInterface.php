<?php

namespace App\Integrations\ServerManagers\Empty\Interfaces;

use App\AppInstance;
use App\Contracts\DatabaseInterface;
use App\OrgServer;

class EmptyDatabaseInterface implements DatabaseInterface
{
    private $database = '';

    public function __construct(OrgServer $server, private ?AppInstance $app_instance = null) {}

    public function existsOrganization()
    {
        return true;
    }

    public function exists()
    {
        return true;
    }

    public function get()
    {
        return null;
    }

    public function add()
    {
        return [
            'id' => null,
            'databasename' => null,
        ];
    }

    public function update() {}

    public function restore() {}

    public function delete()
    {
        return null;
    }
}
