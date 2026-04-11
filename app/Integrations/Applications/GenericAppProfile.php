<?php

namespace App\Integrations\Applications;

class GenericAppProfile extends AppProfile
{
    protected $name = 'generic';

    protected $activation_type = 'none';

    protected $compatibility = ['openid', 'ldap', 'shareable'];
}
