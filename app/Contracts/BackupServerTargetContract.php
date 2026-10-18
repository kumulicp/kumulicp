<?php

namespace App\Contracts;

/**
 * Marks a backup driver that can dump from, and restore into, the database
 * server named by an OrgBackup's `source_org_server_id` / `target_org_server_id`
 * settings.
 *
 * run() and restore() return a 'status' of 'completed' when the work is done by
 * the time they return. A driver that hands the work to a remote service
 * leaves the OrgBackup 'running' and has that service report back (completed or
 * failed) through the backup callback; the database migration waits for either.
 */
interface BackupServerTargetContract extends BackupContract {}
