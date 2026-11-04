<?php

namespace App\Services\AppInstance;

use App\AppInstance;
use App\Integrations\Applications\Application as ApplicationIntegration;
use App\Notifications\AppInstanceUnavailable;
use App\Notifications\AppInstanceUnavailableWarning;
use App\Organization;
use App\Support\SafeErrorMessage;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tracks whether an app instance's API is answering.
 *
 *  1. An API call to an active app fails to connect (or gets a 502/503/504):
 *     the app becomes `unavailable` and ONE warning goes to the system admins
 *     and the organization's admins. API calls still go through for now, and
 *     one that succeeds puts the app back.
 *  2. Every RECHECK_MINUTES the app is probed. If it answers, it goes back to
 *     the status it had.
 *  3. If it doesn't, API calls to it are blocked and ONE "unavailable"
 *     notification is sent. After that nothing more is sent, and the probe
 *     keeps running every RECHECK_MINUTES until it answers.
 *
 * Every transition happens under a row lock and is checked against the app's
 * current status, so concurrent workers can't send a notification twice.
 */
class AvailabilityService
{
    public const RECHECK_MINUTES = 5;

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

            $now = now()->toIso8601String();

            $this->save($locked, AppInstance::STATUS_UNAVAILABLE, [
                'previous_status' => $locked->status,
                'since' => $now,
                'last_error' => SafeErrorMessage::scrub($reason),
                'checked_at' => $now,
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
     * Probes the app, and acts on the answer. Returns whether it answered.
     *
     * @param  (callable(AppInstance): bool)|null  $probe  Replaces the HTTP probe
     */
    public function recheck(AppInstance $app, ?callable $probe = null): bool
    {
        $reachable = $probe ? $probe($app) : (new ApplicationIntegration($app))->isReachable();

        if ($reachable) {
            $this->restore($app);

            return true;
        }

        $blocked = $this->transition($app, function (AppInstance $locked) {
            if (! $locked->isUnavailable()) {
                return false;
            }

            $availability = $locked->setting('availability') ?? [];
            $now = now()->toIso8601String();
            $first_time = ($availability['blocked_at'] ?? null) === null;

            $availability['checked_at'] = $now;
            $availability['blocked_at'] ??= $now;

            $this->save($locked, AppInstance::STATUS_UNAVAILABLE, $availability);

            return $first_time;
        });

        if ($blocked) {
            $this->notify($app, AppInstanceUnavailable::class);
        }

        return false;
    }

    /**
     * Whether the app was last checked long enough ago to check it again.
     */
    public function isDueForRecheck(AppInstance $app): bool
    {
        $checked_at = $app->setting('availability.checked_at');

        return $checked_at === null
            || now()->diffInMinutes(Carbon::parse($checked_at), true) >= self::RECHECK_MINUTES;
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
