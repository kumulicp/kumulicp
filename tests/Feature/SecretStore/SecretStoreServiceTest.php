<?php

use App\Exceptions\ConnectionFailedException;
use App\SecretStore;
use App\Server;
use Illuminate\Support\Facades\Http;

it('resolves a server with no assigned store to the system default store', function () {
    $server = Server::factory()->create(['secret_store_id' => null]);

    $server->secretStore()->put('server/'.$server->id.'/ldap', 'password', 'hunter2');

    $default = SecretStore::default();

    expect($default->driver)->toBe('database');
    expect(app('secret_store')->driver($default)->get('server/'.$server->id.'/ldap', 'password'))->toBe('hunter2');
});

it('resolves a server to its explicitly assigned store', function () {
    $store = SecretStore::factory()->create(['name' => 'Dedicated Store']);
    $server = Server::factory()->create(['secret_store_id' => $store->id]);

    $server->secretStore()->put('ldap', 'password', 'hunter2');

    expect(app('secret_store')->driver($store)->get('ldap', 'password'))->toBe('hunter2');

    // Not visible under the default store.
    expect(app('secret_store')->driver(SecretStore::default())->get('ldap', 'password'))->toBeNull();
});

it('throws for an unknown driver', function () {
    $store = SecretStore::factory()->create(['driver' => 'unknown']);

    app('secret_store')->driver($store);
})->throws(\Exception::class);

it('fails openbao authentication cleanly when AppRole login is rejected', function () {
    $store = SecretStore::factory()->openbao()->create();

    Http::fake([
        '*/v1/auth/approle/login' => Http::response(['errors' => ['permission denied']], 400),
    ]);

    expect(app('secret_store')->driver($store)->testConnection())->toBeFalse();

    app('secret_store')->driver($store)->get('server/1/ldap', 'password');
})->throws(ConnectionFailedException::class);
