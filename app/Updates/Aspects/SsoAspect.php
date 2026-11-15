<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\Updates\Fingerprint;

/** Which single sign-on server the app is set up with */
class SsoAspect extends Aspect
{
    public function key(): string
    {
        return 'sso';
    }

    public function label(): string
    {
        return __('admin.update_aspects.sso');
    }

    public function appliesTo(AppInstance $app_instance): bool
    {
        return $app_instance->usesOwnResources() && (bool) $app_instance->plan?->sso_server;
    }

    public function fingerprint(AppInstance $app_instance): string
    {
        return Fingerprint::of([
            'version_id' => $app_instance->version_id,
            'sso_server_id' => $app_instance->plan?->sso_server_id,
            'instance_sso_server_id' => $app_instance->sso_server_id,
        ]);
    }
}
