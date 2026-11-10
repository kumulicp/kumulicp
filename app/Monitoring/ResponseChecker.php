<?php

namespace App\Monitoring;

use App\Services\AppInstance\AvailabilityService;
use Psr\Http\Message\ResponseInterface;
use Spatie\UptimeMonitor\Helpers\UptimeResponseCheckers\LookForStringChecker;
use Spatie\UptimeMonitor\Helpers\UptimeResponseCheckers\UptimeResponseChecker;
use Spatie\UptimeMonitor\Models\Monitor;

/**
 * Decides whether an answer means the site is up. Needs `http_errors` off in
 * config/uptime-monitor.php: Guzzle otherwise throws on every 4xx/5xx and the
 * checker never sees them.
 *
 *  - An app's monitor: the app answered unless a gateway says nothing is
 *    behind it (AvailabilityService::isDownResponse: a 502/503/504, or the
 *    ingress's own "404 page not found"). A login page, a 401 or a 500 from
 *    one endpoint is an app that is up. This is the same rule the
 *    API calls use, so both agree on what "unavailable" means.
 *  - Any other monitor: what the package did before, any 4xx/5xx is down.
 *
 * Both then look for the monitor's `look_for_string`, if it has one.
 */
class ResponseChecker implements UptimeResponseChecker
{
    public function isValidResponse(ResponseInterface $response, Monitor $monitor): bool
    {
        if (! $this->statusIsFine($response, $monitor)) {
            return false;
        }

        return (new LookForStringChecker)->isValidResponse($response, $monitor);
    }

    public function getFailureReason(ResponseInterface $response, Monitor $monitor): string
    {
        if (! $this->statusIsFine($response, $monitor)) {
            return "Got HTTP status {$response->getStatusCode()} {$response->getReasonPhrase()}.";
        }

        return (new LookForStringChecker)->getFailureReason($response, $monitor);
    }

    private function statusIsFine(ResponseInterface $response, Monitor $monitor): bool
    {
        $status = $response->getStatusCode();

        if ($monitor->app_instance_id === null) {
            return $status < 400;
        }

        // The body only matters for a 404, so it is only read then. (string)
        // rewinds the stream first, and the look_for_string check reads it after
        $body = $status === 404 ? (string) $response->getBody() : '';

        return ! AvailabilityService::isDownResponse($status, $response->getHeaderLine('Content-Type'), $body);
    }
}
