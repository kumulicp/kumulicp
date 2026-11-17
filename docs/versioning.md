# Core versioning

The Kumuli CP core version is defined in **one place**: the `VERSION` file in
the project root (currently `0.5.0`, plain `MAJOR.MINOR.PATCH`, no leading `v`).

Everything else derives from it:

- `config('app.version')` – loaded from `VERSION` in `config/app.php`. Don't
  override it in `.env`.
- `App\Support\CoreVersion` – the API plugins should use.
- `php artisan about` – shows the version under "Kumuli CP".
- The release workflow – refuses to publish unless the tag is `v` + `VERSION`.

## Releasing

1. Change `VERSION` (e.g. `0.5.1`) and merge it.
2. Tag that commit `v0.5.1` (or run the Release workflow with `v0.5.1`).

If the tag and `VERSION` differ the workflow fails before anything is
published, so a release can never report a different version than its tag.

## Using the version from a plugin

```php
use App\Support\CoreVersion;

CoreVersion::current();                 // "0.5.0"
CoreVersion::satisfies('^0.5');         // composer-style constraint
CoreVersion::satisfies('>=0.5.1 <0.7');
CoreVersion::atLeast('0.5.2');
CoreVersion::lessThan('0.6.0');
CoreVersion::compare('0.5.0');          // -1, 0 or 1
CoreVersion::major();                   // 0
CoreVersion::minor();                   // 5
CoreVersion::patch();                   // 0
```

Typical use is guarding behaviour that changed between core versions:

```php
if (CoreVersion::atLeast('0.6.0')) {
    // use the new core API
} else {
    // fall back for older cores
}
```

Always go through `CoreVersion` rather than reading `VERSION` or
`config('app.version')` directly, so any change to how the version is stored
only has to be made in one place.

While the core is `0.x`, treat a minor bump (`0.5` → `0.6`) as potentially
breaking; `^0.5` therefore matches `0.5.x` only.
