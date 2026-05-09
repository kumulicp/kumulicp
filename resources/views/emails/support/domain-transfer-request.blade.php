@component('mail::message')
<h1>{{ ('messages.notification.domain_transfer_request.title') }}</h1>
{{ ('organization.organization') }}: <a href="/admin/organizations/{{ $organization->slug }}">{{ $organization->name }}</a><br />
{{ ('messages.notification.domain_transfer_request.name') }}: {{ $user->name }}<br />
{{ ('messages.notification.domain_transfer_request.phone') }}: {{ $user->phone }}<br />
{{ ('messages.notification.domain_transfer_request.email') }}: {{ $user->email }}<br />
{{ ('messages.notification.domain_transfer_request.domain') }}: {{ $domain->name }}
@endcomponent
