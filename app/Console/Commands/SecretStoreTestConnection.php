<?php

namespace App\Console\Commands;

use App\SecretStore;
use App\Support\Facades\SecretStore as SecretStoreFacade;
use Illuminate\Console\Command;

class SecretStoreTestConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'secret-store:test {id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the connection to a configured secret store';

    public function handle(): int
    {
        $store = SecretStore::find($this->argument('id'));

        if (! $store) {
            $this->error("Secret store [{$this->argument('id')}] not found");

            return self::FAILURE;
        }

        if (SecretStoreFacade::driver($store)->testConnection()) {
            $this->info("Connection to secret store [{$store->name}] succeeded");

            return self::SUCCESS;
        }

        $this->error("Connection to secret store [{$store->name}] failed");

        return self::FAILURE;
    }
}
