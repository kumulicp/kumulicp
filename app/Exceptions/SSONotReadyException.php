<?php

namespace App\Exceptions;

use App\Exceptions\Tasks\WaitingException;

class SSONotReadyException extends WaitingException
{
    /**
     * Get the exception's context information.
     *
     * @return array
     */
    // public function context()
    // {
    // }
}
