<?php

namespace App\Integrations\ServerManagers\AppDatabase;

use App\Contracts\BackupContract;
use App\OrgBackup;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\DbDumper\Databases\MySql;

class BackupInterface implements BackupContract
{
    private string $database = '';

    private string $username = '';

    private string $password = '';

    private $organization;

    public function __construct(
        private OrgBackup $backup)
    {
        $this->organization = $backup->organization;
        $this->host = $backup->org_server->backup_server->server->host;
        $this->database = $backup->app_instance->databasename;
        $this->username = $backup->app_instance->databasename;
        $this->password = $backup->organization->secretpw;
    }

    public function exists()
    {
        return Storage::disk(config('filesystem.app_backup'))->exists($this->backup->backup_name);
    }

    public function get() {}

    public function run()
    {
        $backup_file = $this->backup->app_instance->name.'-dump.sql';
        $backup_location = storage_path('app/backup-temp').'/'.$backup_file;

        $database = MySql::create()
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
        if ($backup_file = $this->backup->backup_name) {
            $backup_location = storage_path('app/backup-temp').'/'.$backup_file;
            $file = Storage::disk(config('filesystems.app_backup'))->get($backup_file);
            Storage::put('backup-temp'.'/'.$this->backup->backup_name, $file);
        }

        $run_restore = Process::run(implode(' ', ['mysql', '-u', $this->username, '-p'.$this->password, '-h', $this->host, '--database', $this->database, '<', $backup_location]));

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
        Storage::disk(config('filesystem.app_backup'))->delete($this->backup->backup_name);
    }
}
