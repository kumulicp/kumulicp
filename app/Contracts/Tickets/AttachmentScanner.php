<?php

namespace App\Contracts\Tickets;

/**
 * Looks at an uploaded file before it is accepted. Install one by pointing
 * `tickets.attachments.scanner` at a class implementing this contract.
 */
interface AttachmentScanner
{
    /**
     * @param  string  $path  Absolute path of the uploaded file
     * @return bool true when the file is clean, false when it must be rejected
     */
    public function isClean(string $path): bool;
}
