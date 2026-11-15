<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Kubernetes\API\Job as BaseJob;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Job extends BaseJob
{
    use UsesRancher;

    // Rancher flags a job it has given up on in metadata.state, and an
    // unrecognised state counts as failed rather than still running.
    protected function state(array $job): string
    {
        if (Arr::get($job, 'metadata.state.error') === true) {
            Log::error(__('messages.api.rancher.error.job', [
                'job' => Arr::get($job, 'metadata.name'),
                'message' => Arr::get($job, 'metadata.state.message'),
            ]), ['organization_id' => $this->organization->id]);

            return 'failed';
        }

        if (Arr::get($job, 'status.active') == 1) {
            return 'running';
        }

        if (Arr::get($job, 'status.succeeded') == 1) {
            return 'success';
        }

        return 'failed';
    }
}
