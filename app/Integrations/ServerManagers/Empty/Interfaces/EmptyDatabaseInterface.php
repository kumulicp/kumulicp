<?php

namespace App\Integrations\ServerManagers\Empty\Interfaces;

use App\Contracts\ServerManager\DatabaseContract;

class EmptyDatabaseInterface implements DatabaseContract
{
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
