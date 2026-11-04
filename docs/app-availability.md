# App availability

An app whose API stops answering gets the `unavailable` status, the system
admins and the organization's admins are told, and the control panel stops
calling it until it is back.

## What happens

1. **An API call fails.** The call can't connect (refused, DNS, timeout) or gets
   a `502`, `503` or `504`. A `500` or `4xx` doesn't count: the app answered, one
   endpoint is just unhappy.
   The app goes from `active` to `unavailable` and **one warning** is sent. The
   call itself fails as a `ConnectionFailedException`, so a task running it goes
   back to pending and tries again later.
   Only `active` apps are marked: an app that is activating, updating or being
   removed isn't expected to answer.
2. **Every 5 minutes** (`CheckUnavailableAppInstances`, scheduled every minute,
   each app only once its last check is 5 minutes old) the app's address is
   probed. Anything but a connection failure or `502`/`503`/`504` counts as up.
   * **Up:** the app gets back the status it had, and its API calls work again.
     A call that succeeds before the first recheck does the same.
   * **Down:** API calls to the app are **blocked** (they throw
     `AppUnavailableException`, a `ConnectionFailedException`, without sending
     anything) and **one** "unavailable" notification is sent.
3. **After that nothing more is sent**, however long the app stays down. The
   probe keeps running every 5 minutes until it answers.

If the app comes back and goes down again, that is a new outage: a new warning.

## Who is told

The admins of the super account (the system admins) and of the organization that
owns the app, by email and in the panel. Only the system admins see the technical
reason. If the app belongs to the super account, its admins are told once.

## Where it lives

* `App\Services\AppInstance\AvailabilityService` - the state machine. Each
  change is made under a row lock and checks the current status, so concurrent
  workers can't send a notification twice. State is kept in the app instance's
  `settings.availability` (`previous_status`, `since`, `last_error`,
  `checked_at`, `blocked_at`).
* `App\Integrations\Integration::send()` has `beforeRequest()`, `requestFailed()`
  and `requestSucceeded()` hooks; `Integrations\Applications\Application`
  (the base of every app API class) uses them. Other integrations (Namecheap,
  Authentik, ...) are unaffected.
* `AppInstance::STATUS_UNAVAILABLE`, `isUnavailable()`, `isApiBlocked()` and the
  `unavailable()` scope.
