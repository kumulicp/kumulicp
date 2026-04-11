<?php

namespace App\Contracts\ServerManager;

interface DatabaseContract
{
    public function exists();

    public function get();

    public function add();

    public function update();

    public function restore();

    public function delete();
}
