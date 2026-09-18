<?php

namespace App\Integrations\Applications\CiviCRMStandalone;

use App\Integrations\ServerManagers\Rancher\Charts\Job\CiviCRMStandaloneJobChart;

class CiviCRMStandaloneJobs extends CiviCRMStandaloneJobChart
{
    public function updateSettings()
    {
        $this->updateLdap();

        return $this;
    }

    public function updateLdap()
    {
        if (config('account_manager.driver') !== 'ldap') {
            return $this;
        }

        $this->run(
            command: ['/usr/local/bin/set-ldap.sh'],
            env: $this->extraEnv(),
        );

        return $this;
    }
}
