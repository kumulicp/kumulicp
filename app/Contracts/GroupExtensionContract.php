<?php

namespace App\Contracts;

interface GroupExtensionContract
{
    public function settings();

    public function values();

    public function views();

    public function conditionally();
}
