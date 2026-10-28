<?php

namespace App\Exceptions\Tasks;

use Exception;

/**
 * The task can never succeed: it is rolled back and failed without being retried.
 */
class PermanentFailure extends Exception {}
