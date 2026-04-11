@component('mail::message')
@if (count($folders) == 1)
One of your team folders is low on storage space!
@else
Some of your team folders are low on storage space!
@endif

@foreach ($folders as $folder)
@if ($folder['percent'] > 90)
@component('mail::panel')
@endif
# {{ $folder['name'] }}
*Current Usuage:* {{ $folder['size'] }}

*Storage Limit:* {{ $folder['quota'] }}

*Percent Used:* {{ round($folder['percent'], 0) }}%

**Storage Left:** {{ $folder['available'] }}

@if ($folder['percent'] > 90)
@endcomponent
@endif
@endforeach

@component('mail::button', ['url' => config('app.url').'/groups'])
Manage Groups
@endcomponent

@component('mail::button', ['url' => $app_instance->address()])
Go to Nextcloud
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
