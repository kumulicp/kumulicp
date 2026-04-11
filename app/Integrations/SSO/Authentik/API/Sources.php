<?php

namespace App\Integrations\SSO\Authentik\API;

use App\AppInstance;
use App\Integrations\SSO\Authentik\Authentik;
use Illuminate\Support\Arr;

class Sources extends Authentik
{
    public function LDAPSync(AppInstance $app_instance)
    {
        $this->resetClient();
        $source = $this->json()->get($this->basePath().'/api/v3/sources/ldap/ldap/');
        if ($content = Arr::get($source, 'content', null)) {
            $this->resetClient();
            $source = $this->json()->put($this->basePath().'/api/v3/sources/ldap/ldap/', $content);
        }

        return $source;
    }
}
