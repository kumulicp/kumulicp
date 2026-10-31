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
        // Records without an organization (cron, console) fall through to the view's "cron job" label.
        if (! empty($log_data['organization_id'])) {
            $this->organization = Organization::find($log_data['organization_id']);
        }
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
