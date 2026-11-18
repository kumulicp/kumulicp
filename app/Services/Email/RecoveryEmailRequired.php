<?php

namespace App\Services\Email;

/**
 * The email the user has now can't become their recovery email (it is on a domain hosted
 * here), and they have no usable one: they have to give one first.
 */
class RecoveryEmailRequired extends MailboxException {}
