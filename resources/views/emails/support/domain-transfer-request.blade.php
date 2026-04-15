@component('mail::message')
<h1>{{ ('messages.notifications.domain_transfer_request.title') }}</h1>
{{ ('organization.organization') }}: <a href="/admin/organizations/{{ $organization->slug }}">{{ $organization->name }}</a><br />
{{ ('messages.notifications.domain_transfer_request.name') }}: {{ $user->name }}<br />
{{ ('messages.notifications.domain_transfer_request.phone') }}: {{ $user->phone }}<br />
{{ ('messages.notifications.domain_transfer_request.email') }}: {{ $user->email }}<br />
{{ ('messages.notifications.domain_transfer_request.domain') }}: {{ $domain->name }}
@endcomponent
