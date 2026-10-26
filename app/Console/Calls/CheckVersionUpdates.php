<?php

namespace App\Console\Calls;

use App\AppVersion;
use App\Support\VersionUpdates\VersionUpdateChecker;
use Throwable;

/**
 * Looks for newer image tags and compatible chart versions for each app's
 * active version. Results show on the app's Versions page.
 */
class CheckVersionUpdates
{
    public function __construct(private ?int $application_id = null) {}

    public function __invoke()
    {
        $checker = new VersionUpdateChecker;

        // Only enabled versions are checked; drop results left on any other version
        AppVersion::where('status', '!=', 'active')
            ->whereNotNull('update_check')
            ->when($this->application_id, fn ($query) => $query->where('application_id', $this->application_id))
            ->update(['update_check' => null]);

        $versions = AppVersion::where('status', 'active')
            ->when($this->application_id, fn ($query) => $query->where('application_id', $this->application_id))
            ->get();

        foreach ($versions as $version) {
            try {
                $checker->check($version);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
