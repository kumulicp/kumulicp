<?php

namespace App\Exceptions\Tasks;

use Exception;

/**
 * An admin asked for something a task's state doesn't allow (retrying one that
 * is running, deleting one that has not finished). The message says why, for the
 * admin.
 */
class TaskOperationRefused extends Exception {}
