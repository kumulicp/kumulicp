@component('mail::message')
<h2>[{{ $request }}] {{ $title }}</h2>
<p><b>{{ ('organization.organization') }}:</b> <a href="{{ env('APP_URL') }}/admin/organizations/{{ $organization->slug }}">{{ $organization->name }}</a><br />
<b>{{ ('messages.notifications.ticket.from') }}:</b> {{ $user->attribute('name') }} &lt;{{ $user->attribute('email') }}&gt;</p>
<p>{{ $body }}</p>
@endcomponent
