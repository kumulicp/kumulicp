@component('mail::message')

## {{ $user->attribute('name') }},

You're permissions have been updated.

@if (array_key_exists('access', $permissions) && count($permissions['access']) > 0)
### Application Access

@foreach ($permissions['access'] as $permission)
@if ($permission['access'] === true || $permission['access'] == 'standard')
You have been given access to {{ $permission['application'] }}!
@elseif ($permission['access'] == 'basic')
You have been given {{ str($permission['label'])->lower() }} access to {{ $permission['application'] }}!
@elseif ($permission['access'] == false)
Your access to {{ $permission['application'] }} has been revoked
@endif

@endforeach
@endif

@if (array_key_exists('added', $permissions) && count($permissions['added']) > 0)
### Permissions Granted

@foreach ($permissions['added'] as $permission)
You have been granted {{ $permission['role'] }} permission in {{ $permission['application'] }}

@endforeach
@endif

@if (array_key_exists('modified', $permissions) && count($permissions['modified']) > 0)
### Permissions Modified

@foreach ($permissions['modified'] as $permission)
Your permissions have changed to {{ $permission['role'] }} in {{ $permission['application'] }}

@endforeach
@endif

@if (array_key_exists('removed', $permissions) && count($permissions['removed']) > 0)
### Permissions Removed

@foreach ($permissions['removed'] as $permission)
Your {{ $permission['role'] }} permission have been revoked in {{ $permission['application'] }}

@endforeach
@endif

@foreach ($apps as $app)
@component('mail::button', ['url' => $app->admin_address()])
Go To {{ $app->label }}
@endcomponent
@endforeach

@endcomponent
