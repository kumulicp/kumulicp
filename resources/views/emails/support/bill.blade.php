@component('mail::message')
<p>{{ __('messages.notification.bill.line_1') }} {{ date('M d, Y', $invoice->next_payment_attempt)}}</p>
<p>{{ __('messages.notification.bill.line_2') }} <a href="{{ config('app.url') }}">{{ config('app.url') }}</a></p>
<p>{{ __('messages.notification.bill.line_3') }}: </p>
<p>{{ implode(', ', $admins) }}</p>
@endcomponent
