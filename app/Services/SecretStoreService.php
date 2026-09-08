<?php

namespace App\Services;

use App\Contracts\SecretStore\SecretStoreContract;
use App\Integrations\SecretStores\Database\SecretStore as DatabaseSecretStore;
use App\Integrations\SecretStores\OpenBao\SecretStore as OpenBaoSecretStore;
use App\SecretStore as SecretStoreModel;
use App\Server;

class SecretStoreService
{
    private $drivers = [
        'database' => DatabaseSecretStore::class,
        'openbao' => OpenBaoSecretStore::class,
    ];

    public function driver(?SecretStoreModel $store = null): SecretStoreContract
    {
        $store = $store ?? SecretStoreModel::default();

        if (! array_key_exists($store->driver, $this->drivers)) {
            throw new \Exception(__('messages.exception.secret_store_driver_fail'));
        }

        $driver = $this->drivers[$store->driver];

        return new $driver($store);
    }

    public function for(Server $server): SecretStoreContract
    {
        return $this->driver($server->secret_store);
    }
}
