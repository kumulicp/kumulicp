<h1>{{ env('APP_URL') }}{{ $log['level_name'] }}</h1>
<h2>@if ($organization) {{ $organization->name }} @else Cron job @endif</h2>
<p><span style="font-weight: bold">{{ ('messages.notifications.critical_log.error') }}:</span> {!! $log['formatted'] !!}</p>
<p><span style="font-weight: bold">{{ ('messages.notifications.critical_log.time') }}:</span> {{ $log['record_datetime'] }}</p>
<p><span style="font-weight: bold">{{ ('messages.notifications.critical_log.user_agent') }}:</span> {{ $log['user_agent'] }}</p>
<p><span style="font-weight: bold">{{ ('messages.notifications.critical_log.remote_ip') }}:</span> {{ $log['remote_addr'] }}</p>
<p><span style="font-weight: bold">{{ ('messages.notifications.critical_log.extra_info') }}:</span> {{ json_encode($log['extra']) }}</p>
<p><span style="font-weight: bold">{{ ('messages.notifications.critical_log.trace_log') }}:</span><pre>{!! json_encode($log['trace'], JSON_PRETTY_PRINT) !!}</pre></p>

