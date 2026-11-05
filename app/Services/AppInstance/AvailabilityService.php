<?php

namespace App\Services\AppInstance;

use App\AppInstance;
use App\Monitoring\AppMonitor;
use App\Notifications\AppInstanceUnavailable;
use App\Notifications\AppInstanceUnavailableWarning;
use App\Organization;
use App\Support\SafeErrorMessage;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

/**
 * Tracks whether an app instance's API is answering.
 *
 *  1. An API call to an active app fails to connect (or gets a 502/503/504):
 *     the app becomes `unavailable` and ONE warning goes to the system admins
 *     and the organization's admins. API calls still go through for now, and
 *     one that succeeds puts the app back.
 *  2. The app's uptime monitor (see App\Monitoring, spatie/laravel-uptime-monitor)
 *     checks it every RECHECK_MINUTES. evaluate() reads what it found: if the
 *     app answers, it goes back to the status it had.
 *  3. If it doesn't, once at least RECHECK_MINUTES have passed since step 1,
 *     API calls to it are blocked and ONE "unavailable" notification is sent.
 *     After that nothing more is sent, and the monitor keeps checking until the
 *     app answers.
 *
 * The monitor does the HTTP; this decides what its results mean. They can't be
 * taken from the package's UptimeCheckFailed event: that fires once when a
 * monitor goes down and then only hourly, never for the recheck that confirms
 * an outage the app's own API calls found first.
 *
 * Every transition happens under a row lock and is checked against the app's
 * current status, so concurrent workers can't send a notification twice.
 */
class AvailabilityService
{
    public const RECHECK_MINUTES = 5;

    // What a gateway answers when nothing is serving behind it. A 500, a 401 or
    // a login page is an app that is up. Used by API calls and by the monitors.
    public const DOWN_STATUS_CODES = [502, 503, 504];

    public static function isDownStatus(int $status): bool
    {
        return in_array($status, self::DOWN_STATUS_CODES, true);
    }

    /**
     * An API call to the app failed. Only an `active` app is marked: while
     * it is activating, updating or being removed, its API is expected not to
     * answer. An app that is already unavailable stays as it is (and sends
     * nothing).
     */
    public function recordFailure(AppInstance $app, string $reason): bool
    {
        $marked = $this->transition($app, function (AppInstance $locked) use ($reason) {
            if ($locked->status !== 'active') {
                return false;
            }

            $this->save($locked, AppInstance::STATUS_UNAVAILABLE, [
                'previous_status' => $locked->status,
                'since' => now()->toIso8601String(),
                'last_error' => SafeErrorMessage::scrub($reason),
                'blocked_at' => null,
            ]);

            return true;
        });

        if ($marked) {
            $this->notify($app, AppInstanceUnavailableWarning::class);
        }

        return $marked;
    }

    /**
     * An API call to the app got through while it was unavailable (and not
     * yet blocked), so it never really went away.
     */
    public function recordSuccess(AppInstance $app): bool
    {
        return $this->restore($app);
    }

    /**
     * Acts on what the app's monitor has found since the app was marked
     * unavailable. Anything the monitor checked before that says nothing about
     * the outage and is ignored.
     *
     * Returns whether the app answers (false also when there is nothing new to
     * go on yet).
     */
    public function evaluate(AppInstance $app, AppMonitor $monitor): bool
    {
        $since = $app->setting('availability.since');
        $checked_at = $monitor->uptime_last_check_date;

        if (! $app->isUnavailable() || $since === null || $checked_at === null) {
            return false;
        }

        $since = Carbon::parse($since);

        if ($checked_at->lte($since)) {
            return false;
        }

        if ($monitor->uptime_status === UptimeStatus::UP) {
            $this->restore($app);

            return true;
        }

        // The warning promised another look in RECHECK_MINUTES: a check made
        // moments after the failure isn't it
        if ($monitor->uptime_status === UptimeStatus::DOWN
            && $checked_at->gte($since->copy()->addMinutes(self::RECHECK_MINUTES))) {
            $this->block($app);
        }

        return false;
    }

    /**
     * The recheck confirmed the outage: API calls are blocked, and the
     * admins get the one "unavailable" notification. Does nothing, and sends
     * nothing, if the app is already blocked.
     */
    public function block(AppInstance $app): bool
    {
        $blocked = $this->transition($app, function (AppInstance $locked) {
            if (! $locked->isUnavailable() || $locked->isApiBlocked()) {
                return false;
            }

            $availability = $locked->setting('availability') ?? [];
            $availability['blocked_at'] = now()->toIso8601String();

            $this->save($locked, AppInstance::STATUS_UNAVAILABLE, $availability);

            return true;
        });

        if ($blocked) {
            $this->notify($app, AppInstanceUnavailable::class);
        }

        return $blocked;
    }

    /**
     * The app answers again: back to the status it had before.
     */
    public function restore(AppInstance $app): bool
    {
        $restored = $this->transition($app, function (AppInstance $locked) {
            if (! $locked->isUnavailable()) {
                return false;
            }

            $status = $locked->setting('availability.previous_status') ?: 'active';

            $this->save($locked, $status, null);

            return true;
        });

        if ($restored) {
            Log::info("App instance {$app->name} is available again");
        }

        return $restored;
    }

    /**
     * Runs $change on a freshly loaded, row-locked copy of the app, then
     * copies what it saved back onto the caller's model. Returns what $change
     * returned (whether it changed anything).
     *
     * @param  callable(AppInstance): bool  $change
     */
    private function transition(AppInstance $app, callable $change): bool
    {
        return DB::transaction(function () use ($app, $change) {
            $locked = AppInstance::whereKey($app->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return false;
            }

            $changed = $change($locked);

            $app->forceFill([
                'status' => $locked->status,
                'settings' => $locked->settings,
            ])->syncOriginalAttributes(['status', 'settings']);

            return $changed;
        });
    }

    private function save(AppInstance $app, string $status, ?array $availability): void
    {
        $settings = $app->settings ?? [];

        if ($availability === null) {
            unset($settings['availability']);
        } else {
            Arr::set($settings, 'availability', $availability);
        }

        $app->status = $status;
        $app->settings = $settings;
        $app->save();
    }

    /**
     * Sends to the system admins (the super account's admins) and the
     * organization's admins. Sending is best effort: a mail or directory
     * problem must not turn into a failure of whatever made the API call.
     *
     * @param  class-string<AppInstanceUnavailable|AppInstanceUnavailableWarning>  $notification
     */
    private function notify(AppInstance $app, string $notification): void
    {
        $organizations = collect([
            [Organization::where('type', 'superaccount')->first(), true],
            [$app->organization, false],
        ])->filter(fn ($pair) => $pair[0] !== null);

        $notified = [];

        foreach ($organizations as [$organization, $system]) {
            if (in_array($organization->id, $notified, true)) {
                continue;
            }

            $notified[] = $organization->id;

            try {
                // System admins get the technical details; an organization's
                // admins don't need to see our internal error text. When the
                // app belongs to the super account itself they are the same
                // people and get the details.
                $details = $system || $organization->type === 'superaccount';

                $organization->notifyAdmins(new $notification($app, $details));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
