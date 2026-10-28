<?php

namespace App\Exceptions\Tasks;

use Exception;
use Throwable;

/**
 * Not a failure: something the task needs isn't ready yet. The task goes back
 * to pending and is tried again after $retry_after seconds (a minute if null).
 */
class WaitingException extends Exception
{
    public function __construct(string $message = '', public readonly ?int $retry_after = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
