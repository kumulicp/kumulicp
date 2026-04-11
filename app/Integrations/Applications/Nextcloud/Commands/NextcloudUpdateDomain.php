<?php

namespace App\Integrations\Applications\Nextcloud\Commands;

class NextcloudUpdateDomain extends RancherJob
{
    public function create()
    {
        return $this->run(['php', 'occ', 'doman', 'example.com', 'restricted']);
    }
}
