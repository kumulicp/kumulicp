@component('mail::message')

## {{ $user->attribute('name') }},

{{ ('messages.notifications.permissions_updated.message') }}

@if (array_key_exists('access', $permissions) && count($permissions['access']) > 0)
### {{ __('messages.notifications.permissions_updated.app_access_title') }}

@foreach ($permissions['access'] as $permission)
@if ($permission['access'] === true || $permission['access'] == 'standard')
{{ __('messages.notifications.permissions_updated.access_granted', ['app' => $permission['application']]) }}
{{ __('messages.notifications.permissions_updated.basic_access_granted', ['role' => str($permission['label'])->lower(), 'app' => $permission['application']]) }}
@elseif ($permission['access'] == false)
{{ __('messages.notifications.permissions_updated.access_revoked', ['app' => $permission['application']]) }}
@endif

@endforeach
@endif

@if (array_key_exists('added', $permissions) && count($permissions['added']) > 0)
### {{ __('messages.notifications.permissions_updated.permissions_granted_title') }}

@foreach ($permissions['added'] as $permission)
{{ __('messages.notifications.permissions_updated.permissions_granted', ['app' => $permission['application'], 'role' => $permission['role']]) }}

@endforeach
@endif

@if (array_key_exists('modified', $permissions) && count($permissions['modified']) > 0)
### {{ __('messages.notifications.permissions_updated.permissions_modified_title') }}

@foreach ($permissions['modified'] as $permission)
{{ __('messages.notifications.permissions_updated.permissions_modified', ['app' => $permission['application'], 'role' => $permission['role']]) }}

@endforeach
@endif

@if (array_key_exists('removed', $permissions) && count($permissions['removed']) > 0)
### {{ __('messages.notifications.permissions_updated.permissions_removed_title', ['app' => $permission['application']]) }}

@foreach ($permissions['removed'] as $permission)
{{ __('messages.notifications.permissions_updated.permissions_removed', ['app' => $permission['application'], 'role' => $permission['role']]) }}

@endforeach
@endif

@foreach ($apps as $app)
@component('mail::button', ['url' => $app->admin_address()])
{{ __('messages.notifications.permissions_updated.go_to', ['app' => $app->label]) }}
@endcomponent
@endforeach

@endcomponent
