<?php

namespace App\Integrations\SSO\Authentik\API;

use App\AppInstance;
use App\Integrations\SSO\Authentik\Authentik;
use Illuminate\Support\Arr;

class Sources extends Authentik
{
    public function LDAPSync(AppInstance $app_instance)
    {
        $slug = $this->org_server->server->setting('ldap_source_slug') ?? 'ldap';
        $this->resetClient();
        $source = $this->json()->get($this->basePath().'/api/v3/sources/ldap/'.$slug.'/');
        if ($content = Arr::get($source, 'content', null)) {
            $this->resetClient();
            $source = $this->json()->put($this->basePath().'/api/v3/sources/ldap/'.$slug.'/', $content);
        }

        return $source;
    }
}
