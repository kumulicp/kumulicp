@component('mail::message')
<h2>[{{ $request }}] {{ $title }}</h2>
<p><b>{{ __('organization.organization') }}:</b> <a href="{{ env('APP_URL') }}/admin/organizations/{{ $organization->slug }}">{{ $organization->name }}</a><br />
<b>{{ __('messages.notification.ticket.from') }}:</b> {{ $user->attribute('name') }} &lt;{{ $user->attribute('email') }}&gt;</p>
<p>{!! $body !!}</p>
@endcomponent
