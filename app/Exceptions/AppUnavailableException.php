<?php

namespace App\Exceptions;

/**
 * Thrown instead of sending an API call to an app that has been confirmed
 * unavailable. It is a ConnectionFailedException, so a task that hits it goes
 * back to pending and tries again later, once the app is back.
 */
class AppUnavailableException extends ConnectionFailedException {}
