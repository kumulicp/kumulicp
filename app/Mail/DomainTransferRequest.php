<?php

namespace App\Mail;

use App\Organization;
use App\OrgDomain;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DomainTransferRequest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $organization;

    public $user;

    public $domain;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Organization $organization, User $user, OrgDomain $domain)
    {
        $this->organization = $organization;
        $this->user = $user;
        $this->domain = $domain;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.support.domain-transfer-request');
    }
}
