<?php

namespace App\Mail;

use App\Support\Facades\AccountManager;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class SubscriptionBilling extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $admins = [];

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public $invoice)
    {
        $admins = AccountManager::accounts($invoice->owner())->users()->orgAdmins();

        if ($admins) {
            foreach ($admins as $admin) {
                $this->admins[] = $admin->attribute('first_name').' '.$admin->attribute('last_name');
            }
        }
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->replyTo(Settings::get('invoice_reply_to_email'), Settings::get('invoice_reply_to_name'))
            ->subject('Invoice')
            ->markdown('emails.support.bill');
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->invoice->download([
                'vendor' => Settings::get('invoice_vendor_name'),
                'product' => Settings::get('invoice_vendor_product'),
                'street' => Settings::get('invoice_vendor_street'),
                'location' => Settings::get('invoice_vendor_location'),
                'phone' => Settings::get('invoice_vendor_phone_number'),
                'email' => Settings::get('invoice_vendor_email'),
                'url' => Settings::get('invoice_vendor_url'),
                'vendorVat' => Settings::get('invoice_vendor_vat'),
            ])->getContent(), 'invoice.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
