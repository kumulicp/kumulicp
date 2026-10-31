<?php

namespace App\Exceptions;

use Exception;

/**
 * A version test action that isn't allowed right now. The message is meant for
 * the admin who asked for it.
 */
class VersionTestException extends Exception {}
