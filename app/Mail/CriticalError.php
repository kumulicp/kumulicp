<?php

namespace App\Mail;

use App\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CriticalError extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $log;

    public $organization;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($log_data)
    {
        $this->log = $log_data;
        if (array_key_exists('organization_id', $log_data)) {
            $this->organization = Organization::find($log_data['organization_id']);
        }

        $this->organization = Organization::where('type', 'superaccount')->first();
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.logs.critical');
    }
}
