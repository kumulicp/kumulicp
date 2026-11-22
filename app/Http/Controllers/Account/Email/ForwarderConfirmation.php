<?php

namespace App\Http\Controllers\Account\Email;

use App\Http\Controllers\Controller;
use App\Services\Email\ForwarderService;

/**
 * Opened from the email sent to an address that someone wants mail forwarded to. The person who
 * opens it has no account here, and the link is signed, so there is nothing else to check: the
 * page only says whether it worked.
 */
class ForwarderConfirmation extends Controller
{
    public function show(int $destination, string $token, ForwarderService $forwarders)
    {
        $confirmed = $forwarders->confirm($destination, $token);

        return response()
            ->view('forwarders.confirmed', ['confirmed' => $confirmed])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
