<?php

namespace App\Contracts\ServerManager;

interface AppInterface
{
    public function exists();

    public function get();

    public function isActive();

    public function add();

    public function update();

    public function delete();
}
