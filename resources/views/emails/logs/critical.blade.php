<h1>{{ env('APP_URL') }}{{ $log['level_name'] }}</h1>
<h2>@if ($organization) {{ $organization->name }} @else Cron job @endif</h2>
<p><span style="font-weight: bold">Error:</span> {!! $log['formatted'] !!}</p>
<p><span style="font-weight: bold">Time:</span> {{ $log['record_datetime'] }}</p>
<p><span style="font-weight: bold">User Agent:</span> {{ $log['user_agent'] }}</p>
<p><span style="font-weight: bold">Remote IP:</span> {{ $log['remote_addr'] }}</p>
<p><span style="font-weight: bold">Extra info:</span> {{ json_encode($log['extra']) }}</p>
<p><span style="font-weight: bold">Trace log:</span><pre>{!! json_encode($log['trace'], JSON_PRETTY_PRINT) !!}</pre></p>

