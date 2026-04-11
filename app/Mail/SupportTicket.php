<?php

namespace App\Mail;

use App\Support\Facades\AccountManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportTicket extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public $organization;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public string $title, public string $body, public string $request)
    {
        $user = auth()->user();
        $this->organization = $user->organization;
        $this->user = AccountManager::users()->find($user->username);
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $user = $this->user;

        return $this->replyTo($user->attribute('email'), $user->attribute('name'))
            ->subject('['.$this->request.'] '.$this->title)
            ->markdown('emails.support.ticket');
    }
}
