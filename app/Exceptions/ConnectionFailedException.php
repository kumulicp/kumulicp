<?php

namespace App\Exceptions;

use App\Exceptions\Tasks\RetryableException;

class ConnectionFailedException extends RetryableException
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
