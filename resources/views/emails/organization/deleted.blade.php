@component('mail::message')
# {{ __('messages.notification.organization_deleted') }}

{{ __('messages.notification.organization_deleted_notice', ['organization' => $organization_name]) }}

@if ($reason)
{{ __('messages.notification.organization_deleted_reason', ['reason' => $reason]) }}
@endif
@endcomponent
