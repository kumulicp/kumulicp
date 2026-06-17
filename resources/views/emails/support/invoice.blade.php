@component('mail::message')
<p>{{ __('messages.notification.invoice.line_1') }}: {{ $description }}</p>
<p>{{ __('messages.notification.invoice.line_2') }} <a href="{{ config('app.url') }}">{{ config('app.url') }}</a></p>
<p>{{ __('messages.notification.invoice.line_3') }}:</p>
<p>@foreach($admins as $admin){{ $admin->attribute('first_name') }} {{ $admin->attribute('last_name') }}, @endforeach</p>
@endcomponent
