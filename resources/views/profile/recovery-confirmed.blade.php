<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ config('app.name') }}</title>
        <style>
            body { font-family: system-ui, sans-serif; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; background: #f4f5f7; color: #1f2933; }
            main { background: #fff; padding: 2rem 2.5rem; border-radius: 0.5rem; max-width: 28rem; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12); }
            p { margin: 0 0 1.25rem; line-height: 1.5; }
            a { display: inline-block; padding: 0.5rem 1.25rem; border-radius: 0.25rem; background: #154ec1; color: #fff; text-decoration: none; }
        </style>
    </head>
    <body>
        <main id="recoveryConfirmation" data-confirmed="{{ $confirmed ? 'true' : 'false' }}">
            <p>{{ $confirmed ? __('profile.recovery.confirmed') : __('profile.recovery.invalid') }}</p>
            <a id="continue" href="{{ url('/profile') }}">{{ __('profile.recovery.continue') }}</a>
        </main>
    </body>
</html>
