@component('mail::message')
# {{ $app_instance->label }}

{{ $message }}

@component('mail::panel')
**{{ __('messages.notification.app_unavailable_details.app') }}:** {{ $app_instance->label }}

**{{ __('messages.notification.app_unavailable_details.organization') }}:** {{ $organization->name }}

@if ($since)
**{{ __('messages.notification.app_unavailable_details.since') }}:** {{ \Carbon\Carbon::parse($since)->toDayDateTimeString() }}

@endif
@if ($reason)
**{{ __('messages.notification.app_unavailable_details.reason') }}:** {{ $reason }}
@endif
@endcomponent

{{ __('labels.thanks') }},<br>
{{ config('app.name') }}
@endcomponent
