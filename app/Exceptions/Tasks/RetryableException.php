<?php

namespace App\Exceptions\Tasks;

use Exception;

/**
 * A failure that is likely to clear up by itself (a server that can't be
 * reached, a service that is restarting). The task goes back to pending and is
 * tried again later, for as long as it takes.
 */
class RetryableException extends Exception {}
