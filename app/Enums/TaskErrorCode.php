<?php

namespace App\Enums;

/**
 * Why a task is in the state its error message describes. The column holds
 * plain strings (older rows and the odd action may hold one that isn't here),
 * so it is not cast: use Task::errorCode() to read it and ->value to write it.
 */
enum TaskErrorCode: string
{
    // The runner's own
    case WAITING = 'waiting';
    case ERROR = 'error';
    case PERMANENT = 'permanent';
    case THROWABLE = 'throwable';
    case FAILED = 'failed';
    case FAILED_COMPLETE = 'failed_complete';
    case EXCEPTION = 'exception';
    case STEP_FAILED = 'step_failed';
    case DEPENDENCY_FAILED = 'dependency_failed';
    case DEADLINE_EXCEEDED = 'deadline_exceeded';
    case CANCELLED = 'cancelled';
    case CONNECTION_FAILED = 'connection_failed';

    // Actions'
    case APP_NOT_FOUND = 'app_not_found';
    case NO_ORG_DOMAIN = 'no_org_domain';
    case STRIPE_INVOICE_ORGANIZATION_FAILED = 'stripe_invoice_organization_failed';
    case USER_NOT_FOUND = 'user_not_found';
    case CREATE_USER_FAILED = 'create_user_failed';
    case UPDATE_USER_ROLE_FAILED = 'update_user_role_failed';
    case UPDATE_USER_ROLES_FAILED = 'update_user_roles_failed';
    case PROCESS_PERMISSIONS = 'process_permissions';
    case JOB_CREATE_FAILED = 'job_create_failed';
    case JOB_UNAVAILABLE = 'job_unavailable';

    /**
     * Whether it means something went wrong and the task is being tried again,
     * as opposed to waiting for something that is only slow.
     */
    public function isTransient(): bool
    {
        return in_array($this, [self::CONNECTION_FAILED, self::ERROR], true);
    }

    /**
     * What goes in the column for a code given as an enum or as a string.
     */
    public static function valueOf(self|string|null $code): ?string
    {
        return $code instanceof self ? $code->value : $code;
    }
}
