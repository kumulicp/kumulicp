<?php

namespace App\Console\Commands;

use App\Actions\Apps\ApplicationUpdate;
use App\AppInstance;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\ServerInterface;
use Illuminate\Console\Command;
use PDO;
use PDOException;

/**
 * One-off repair for app databases whose user still has the organization's
 * secretpw as password, while the app is configured with its own db_password
 * (AppInstance::dbPassword()).
 *
 * Reports by default. With --fix it re-runs DatabaseInterface::add(), which
 * resets the database user's password to dbPassword(), then runs the standard
 * app update so the chart picks the password up. The app can't reach its
 * database between the reset and the end of that update.
 */
class SyncAppDatabasePasswords extends Command
{
    protected $signature = 'app-databases:sync-passwords {--fix : Reset the database user password and update the app}';

    protected $description = 'Find app databases whose user password differs from the one the app is configured with, and optionally repair them';

    private const ACCEPTED = 'accepted';

    private const DENIED = 'denied';

    private const UNREACHABLE = 'unreachable';

    private const ACCESS_DENIED_CODE = 1045;

    public function handle(): int
    {
        $counts = ['ok' => 0, 'legacy' => 0, 'unknown' => 0, 'unreachable' => 0, 'fixed' => 0];

        $app_instances = AppInstance::query()
            ->whereNotNull('databasename')
            ->whereNotNull('database_server_id')
            ->with(['database_server.server', 'organization'])
            ->lazyById();

        foreach ($app_instances as $app_instance) {
            $state = $this->state($app_instance);
            $counts[$state]++;

            if ($state === 'ok') {
                continue;
            }

            $this->line("{$app_instance->id} {$app_instance->databasename}: {$state}");

            if ($state === 'legacy' && $this->option('fix')) {
                if ($app_instance->status !== 'active') {
                    $this->warn("  skipped, app status is {$app_instance->status}");

                    continue;
                }

                ServerInterface::connect($app_instance->database_server, $app_instance)->add();
                ActionFacade::execute(new ApplicationUpdate($app_instance), background: true);
                $counts['fixed']++;
            }
        }

        $this->info("ok: {$counts['ok']}, legacy password: {$counts['legacy']}, unknown password: {$counts['unknown']}, unreachable: {$counts['unreachable']}, fixed: {$counts['fixed']}");

        return self::SUCCESS;
    }

    /**
     * ok: the user accepts dbPassword(). legacy: it only accepts the
     * organization's secretpw. unknown: it accepts neither.
     */
    private function state(AppInstance $app_instance): string
    {
        $host = $app_instance->database_server->server->host;

        $login = $this->login($host, $app_instance->databasename, $app_instance->dbPassword());
        if ($login !== self::DENIED) {
            return $login === self::ACCEPTED ? 'ok' : 'unreachable';
        }

        return $this->login($host, $app_instance->databasename, $app_instance->organization->secretpw) === self::ACCEPTED
            ? 'legacy'
            : 'unknown';
    }

    private function login(string $host, string $username, string $password): string
    {
        $port = config('database.connections.mysql.port', '3306');

        try {
            new PDO("mysql:host={$host};port={$port}", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            return self::ACCEPTED;
        } catch (PDOException $e) {
            // The driver code sits in errorInfo, or in getCode() when PDO fails while connecting.
            return ($e->errorInfo[1] ?? $e->getCode()) == self::ACCESS_DENIED_CODE ? self::DENIED : self::UNREACHABLE;
        }
    }
}
