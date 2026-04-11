<?php

namespace App\Contracts;

interface BackupContract
{
    public function exists();

    public function get();

    public function run();

    public function update();

    public function restore();

    public function delete();
}
