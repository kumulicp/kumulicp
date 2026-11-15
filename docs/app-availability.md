# App availability

An app whose API stops answering gets the `unavailable` status, the system
admins and the organization's admins are told, and the control panel stops
calling it until it is back.

The checking itself is done by
[spatie/laravel-uptime-monitor](https://github.com/spatie/laravel-uptime-monitor):
every app instance has a monitor pointed at its address. Availability is what
the panel decides *about the app* from what the monitor finds, so the monitor's
history is also there for anything else built on it (certificate expiry,
uptime reports, a status page, ...).

## What happens

1. **An API call fails.** The call can't connect (refused, DNS, timeout), gets
   a `502`, `503` or `504`, or gets the ingress's own `404 page not found` (a
   `text/plain` 404 with exactly that body; Traefik answers it for an app scaled
   to 0, because it drops the route of a Service with no ready pods). A `500` or
   any other `4xx` doesn't count: the app answered, one endpoint is just
   unhappy. An app's own 404 (JSON or HTML) is never mistaken for the ingress's.
   The app goes from `active` to `unavailable` and **one warning** is sent. The
   call itself fails as a `ConnectionFailedException`, so a task running it goes
   back to pending and tries again later.
   Only `active` apps are marked: an app that is activating, updating or being
   removed isn't expected to answer.
2. **The monitor checks the app every 5 minutes** (`monitor:check-uptime`,
   scheduled every minute; an app's monitor keeps to its 5 minute interval even
   while it is down). `CheckUnavailableAppInstances`, also every minute, reads the
   results for the unavailable apps. Only checks made *after* the failure count.
   * **Up:** the app gets back the status it had, its API calls work again, and
     **one** "back" notification is sent. A call that succeeds before that does
     the same.
   * **Down, 5 or more minutes after the failure** (the warning promised
     another look in 5 minutes, so a check made moments after the failure
     doesn't count): API calls to the app are **blocked** (they throw
     `AppUnavailableException`, a `ConnectionFailedException`, without sending
     anything) and **one** "unavailable" notification is sent.
3. **After that nothing more is sent**, however long the app stays down. The
   monitor keeps checking every 5 minutes until the app answers; then the one
   "back" notification above.

If the app comes back and goes down again, that is a new outage: a new warning.

### When notifications are sent

* **Down** (the warning, then "unavailable"): only for an app that was `active`
  when the call failed. An app that is activating, updating, deactivating,
  deactivated or being deleted isn't marked and nobody is told, and once an
  unavailable app is being updated the monitor's results stop mattering (no
  blocking, no "unavailable" notification).
* **Back:** once per outage, and only for an app that was unavailable since it
  was last active. That is either of the ways above (a call that goes through, a
  monitor check that finds it up) or a fix: an upgrade or update takes the app
  `unavailable` → `updating` → `active` without any call or check finding it
  answering. `AppInstanceObserver` notices the app becoming `active` while its
  `settings.availability` is still there and calls
  `AvailabilityService::settle()`, which tells the admins and forgets the
  outage. An app that was never unavailable, or whose outage was already
  settled, gets nothing.

## Who is told

The admins of the super account (the system admins) and of the organization that
owns the app, by email and in the panel. Only the system admins see the technical
reason. If the app belongs to the super account, its admins are told once.

## The monitors

`App\Monitoring\AppMonitors` keeps one monitor per app instance; it is not
something to manage by hand.

* **Created** when the app becomes active, pointing at `AppInstance::address()`,
  checking every 5 minutes, with the certificate check on for https addresses.
* **Only checking while the app is `active` or `unavailable`.** It is switched
  off for any other status (activating, updating, deactivating, ...) so those
  don't register as outages, and switched back on, with its old results
  forgotten, when the app is active again.
* **Moved** when the app's address changes (a new primary domain, a rename).
* **Removed** with the app by the database: `monitors.app_instance_id` is a
  foreign key to `app_instances` with `ON DELETE CASCADE`. That holds for every
  way an app can be deleted, including the ones that skip model events, like
  deleting an organization's apps in one query. Don't rely on code (an observer,
  a cleanup job) for this; a monitor for an app that is gone can't exist.
* An app that lives at its parent's address (`Application` domain option
  `parent`) has no monitor of its own: the monitor's URL is unique, and the
  parent's monitor is the one that checks that address.
* The rest follows from changes to the app instance (`AppInstanceObserver`).
  `SyncAppMonitors` (daily) and the `upgrade` command run
  `AppMonitors::reconcile()`, which also creates the monitors for apps that
  existed before this and fixes any address that drifted.
* A monitor added by hand (`php artisan monitor:create`) is left alone and works
  as the package documents.

## How the package is set up

All in `config/uptime-monitor.php`:

* `monitor_model` is `App\Monitoring\AppMonitor`, the package's model plus
  `app_instance_id` (a column added to `monitors`) and an interval-respecting
  `shouldCheckUptime()` for app monitors. The package rechecks a monitor that is
  down on every run.
* `response_checker` is `App\Monitoring\ResponseChecker`, and `guzzle_options`
  has `http_errors` off so that checker sees every answer. For an app's monitor
  only `502`/`503`/`504`, the ingress's `404 page not found`, or no connection is down, the same rule the API calls
  use (`AvailabilityService::isDownResponse()`); for any other monitor any `4xx`/`5xx`
  is, as before. A monitor's `look_for_string` still applies.
* The package's "went down" and "recovered" emails to `ERRORS_EMAIL` are
  `App\Monitoring\Notifications\UptimeCheckFailed` / `UptimeCheckRecovered`, which
  skip app monitors (the notifications above replace them) and are otherwise
  unchanged. Certificate emails are the package's, for app monitors too.

The package's `UptimeCheckFailed` event is not what the panel acts on: it fires
once when a monitor first goes down and then only hourly, never for the recheck
that confirms an outage the API calls found first. The monitor's stored result
(`uptime_status`, `uptime_last_check_date`) is.

## Where it lives

* `App\Services\AppInstance\AvailabilityService` - the state machine. Each
  change is made under a row lock and checks the current status, so concurrent
  workers can't send a notification twice. State is kept in the app instance's
  `settings.availability` (`previous_status`, `since`, `last_error`,
  `blocked_at`).
* `App\Integrations\Integration::send()` has `beforeRequest()`, `requestFailed()`
  and `requestSucceeded()` hooks; `Integrations\Applications\Application`
  (the base of every app API class) uses them. Other integrations (Namecheap,
  Authentik, ...) are unaffected.
* `AppInstance::STATUS_UNAVAILABLE`, `isUnavailable()`, `isApiBlocked()`, the
  `unavailable()` scope and `monitor()`.
* `App\Monitoring\*` - the monitor model, lifecycle, response checker and
  notifications.

## Known limits

* In local development a monitor can't reach `*.nip.io` style addresses the way
  API calls do (`DEV_INGRESS_GATEWAY`), so it will report them down.
* Apps that share an address (a hub and its children) share a monitor, but each
  app is marked by its own API calls, so a hub outage can warn once per child
  that makes a call.
* An app whose root answers but whose API endpoints return `502`/`503`/`504` is
  seen as back by the monitor, and is marked again by the next API call.
