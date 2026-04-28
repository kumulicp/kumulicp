{{ __('auth.username') }}: {{ $user['username'] }} <br />
<a href="{{ env('APP_URL') }}/public/setpassword/{{ $code }}">{{ __('passwords.set_new') }}</a>
