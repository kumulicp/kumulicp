@component('mail::message')
@if (count($folders) == 1)
{{ __('messages.api.nextcloud.team_folders.quota_reached.low_1') }}
@else
{{ __('messages.api.nextcloud.team_folders.quota_reached.low_2') }}
@endif

@foreach ($folders as $folder)
@if ($folder['percent'] > 90)
@component('mail::panel')
@endif
# {{ $folder['name'] }}
*{{ __('messages.api.nextcloud.team_folders.quota_reached.current') }}:* {{ $folder['size'] }}

*{{ __('messages.api.nextcloud.team_folders.quota_reached.limit') }}:* {{ $folder['quota'] }}

*{{ __('messages.api.nextcloud.team_folders.quota_reached.percent_used') }}:* {{ round($folder['percent'], 0) }}%

**{{ __('messages.api.nextcloud.team_folders.quota_reached.remaining') }}:** {{ $folder['available'] }}

@if ($folder['percent'] > 90)
@endcomponent
@endif
@endforeach

@component('mail::button', ['url' => config('app.url').'/groups'])
{{ __('messages.api.nextcloud.team_folders.quota_reached.manage_groups') }}
@endcomponent

@component('mail::button', ['url' => $app_instance->address()])
{{ __('messages.api.nextcloud.team_folders.quota_reached.go_to_nextcloud') }}
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
