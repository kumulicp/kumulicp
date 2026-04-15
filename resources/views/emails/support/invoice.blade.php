@component('mail::message')
<p>{{ ('messages.notifications.invoice.line_1') }}: {{ $description }}</p>
<p>{{ ('messages.notifications.invoice.line_2') }} <a href="{{ env('APP_URL') }}">{{ env('APP_URL') }}</a></p>
<p>{{ ('messages.notifications.invoice.line_3') }}:</p>
<p>@foreach($admins as $admin){{ $admin->attribute('first_name') }} {{ $admin->attribute('last_name') }}, @endforeach</p>
@endcomponent
