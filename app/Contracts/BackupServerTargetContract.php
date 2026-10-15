<?php

namespace App\Contracts;

/**
 * Marks a backup driver that can dump from, and restore into, the database
 * server named by an OrgBackup's `source_org_server_id` / `target_org_server_id`
 * settings, and that finishes each run/restore before returning (the database
 * migration treats a returned call as a finished step).
 *
 * Drivers that hand the work to a remote service and report back through a
 * callback must not implement this until they honour both.
 */
interface BackupServerTargetContract extends BackupContract {}
