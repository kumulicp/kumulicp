@component('mail::message')
<h1>Domain Transfer Request</h1>
Organization: <a href="/admin/organizations/{{ $organization->slug }}">{{ $organization->name }}</a><br />
Name: {{ $user->name }}<br />
Phone: {{ $user->phone }}<br />
Email: {{ $user->email }}<br />
Domain: {{ $domain->name }}
@endcomponent
