@component('mail::message')
<p>{{ ('messages.notification.bill.line_1') }} {{ date('M d, Y', $invoice->next_payment_attempt)}}</p>
<p>{{ ('messages.notification.bill.line_2') }} <a href="{{ env('APP_URL') }}">{{ env('APP_URL') }}</a></p>
<p>{{ ('messages.notification.bill.line_3') }}: </p>
<p>{{ implode(', ', $admins) }}</p>
@endcomponent
