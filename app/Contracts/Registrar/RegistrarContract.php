<?php

namespace App\Contracts\Registrar;

use App\OrgDomain;
use App\Tld;

interface RegistrarContract
{
    public function register(OrgDomain $org_domain, int $years, $extended_attributes = null);

    public function select(OrgDomain $domain): object;

    public function list(): array;

    public function info(string $domain_name): array;

    public function check(string $domain_name): array;

    public function pricing(Tld $tld, $domain_name): object;

    public function tldList(): array;
}
