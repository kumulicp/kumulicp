@component('mail::message')
<h1>{{ __('messages.notification.domain_transfer_request.title') }}</h1>
{{ __('organization.organization') }}: @if (\App\Support\Facades\Tenancy::isSingle()){{ $organization->name }}@else<a href="/admin/organizations/{{ $organization->slug }}">{{ $organization->name }}</a>@endif<br />
{{ __('messages.notification.domain_transfer_request.name') }}: {{ $user->name }}<br />
{{ __('messages.notification.domain_transfer_request.phone') }}: {{ $user->phone }}<br />
{{ __('messages.notification.domain_transfer_request.email') }}: {{ $user->email }}<br />
{{ __('messages.notification.domain_transfer_request.domain') }}: {{ $domain->name }}
@endcomponent
