@component('mail::message')
<p>Attached is your invoice for: {{ $description }}</p>
<p>If you wouldn't expecting this place login at <a href="{{ env('APP_URL') }}">{{ env('APP_URL') }}</a> and submit an inquiry</p>
<p>Or check with other admins:</p>
<p>@foreach($admins as $admin){{ $admin->attribute('first_name') }} {{ $admin->attribute('last_name') }}, @endforeach</p>
@endcomponent
