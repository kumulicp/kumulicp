<?php

namespace App\Exceptions;

/**
 * The registrar could not be reached (timeout, DNS failure, refused connection).
 * Callers can show cached data and disable whatever needs the registrar until it answers again.
 */
class RegistrarUnavailableException extends DomainRegistrationException {}
