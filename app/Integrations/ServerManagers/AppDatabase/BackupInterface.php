<?php

namespace App\Integrations\ServerManagers\AppDatabase;

use App\Contracts\BackupContract;
use App\OrgBackup;
use App\OrgServer;
use App\Support\Facades\ServerInterface;
use Illuminate\Http\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\DbDumper\Databases\MySql;

class BackupInterface implements BackupContract
{
    private string $database = '';

    private string $username = '';

    private string $password = '';

    private string $host = '';

    public function __construct(
        private OrgBackup $backup)
    {
        $this->host = $this->resolveHost();
        $this->database = $backup->app_instance->databasename;
        $this->username = $backup->app_instance->databasename;
        $this->password = $backup->organization->secretpw;
    }

    /**
     * Backups dump from, and restores load into, the backup server by default.
     * A backup/restore can instead name the database server explicitly through
     * its settings: `source_org_server_id` to dump a specific server (a
     * migration dumps the app's live database, not a replica), or
     * `target_org_server_id` to restore into a different database server than
     * the one the dump came from.
     */
    private function resolveHost(): string
    {
        if ($org_server = $this->overrideOrgServer()) {
            return $org_server->server->host;
        }

        return $this->backup->org_server->backup_server->server->host;
    }

    private function overrideOrgServer(): ?OrgServer
    {
        $key = $this->backup->action === 'restore' ? 'target_org_server_id' : 'source_org_server_id';

        if ($id = Arr::get($this->backup->settings ?? [], $key)) {
            return OrgServer::with('server')->findOrFail($id);
        }

        return null;
    }

    public function exists()
    {
        return Storage::disk(config('filesystems.app_backup'))->exists($this->backup->backup_name);
    }

    public function get() {}

    public function run()
    {
        $backup_file = $this->backup->app_instance->name.'-dump.sql';
        $backup_location = storage_path('app/backup-temp').'/'.$backup_file;

        MySql::create()
            ->setDbName($this->database)
            ->setHost($this->host)
            ->setUserName($this->username)
            ->setPassword($this->password)
            ->dumpToFile($backup_location);

        $backup = Storage::disk(config('filesystems.app_backup'))->put('', new File($backup_location));

        $this->backup->backup_name = $backup;
        $this->backup->completed_at = now();
        $this->backup->save();

        return [
            'job_id' => '',
            'status' => 'completed',
        ];
    }

    public function update() {}

    public function restore()
    {
        $backup_file = $this->backup->backup_name;

        if (! $backup_file) {
            throw new \Exception(__('messages.exception.no_backup_file'));
        }

        // Restoring to a different database server: it needs the app's
        // database and user first. add() is a no-op for what already exists.
        if ($target = $this->overrideOrgServer()) {
            ServerInterface::connect($target, $this->backup->app_instance)->add();
        }

        $temp_file = 'backup-temp/'.$backup_file;
        $backup_location = storage_path('app/'.$temp_file);

        $stream = Storage::disk(config('filesystems.app_backup'))->readStream($backup_file);
        Storage::writeStream($temp_file, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            // Everything reaches the shell through the environment, never
            // interpolated into the command line (nor visible in `ps`).
            $run_restore = Process::env([
                'MYSQL_PWD' => $this->password,
                'RESTORE_HOST' => $this->host,
                'RESTORE_USER' => $this->username,
                'RESTORE_DATABASE' => $this->database,
                'RESTORE_FILE' => $backup_location,
            ])->run(['sh', '-c', 'exec mysql -u "$RESTORE_USER" -h "$RESTORE_HOST" --database "$RESTORE_DATABASE" < "$RESTORE_FILE"']);
        } finally {
            Storage::delete($temp_file);
        }

        if ($run_restore->failed()) {
            throw new \Exception($run_restore->errorOutput());
        }

        return [
            'job_id' => '',
            'status' => 'completed',
        ];
    }

    public function delete()
    {
        Storage::disk(config('filesystems.app_backup'))->delete($this->backup->backup_name);
    }
}
