<?php

namespace App\Contracts\AccountManager;

interface AccountContract
{
    public function update($data);

    public function users();

    public function groups();

    public function destroy();
}
