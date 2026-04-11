@component('mail::message')
<p>Attached is your invoice for your recurring bill for {{ date('M d, Y', $invoice->next_payment_attempt)}}</p>
<p>If you would like to cancel your subscription, login at <a href="{{ env('APP_URL') }}">{{ env('APP_URL') }}</a></p>
<p>If you don't have access, any of these admins are able to do this: </p>
<p>{{ implode(', ', $admins) }}</p>
@endcomponent
