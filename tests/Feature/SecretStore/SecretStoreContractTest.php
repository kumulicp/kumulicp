<?php

it('stores and retrieves a value', function (string $driver) {
    [$store] = makeSecretStore($driver);

    $store->put('server/1/ldap', 'password', 'hunter2');

    expect($store->get('server/1/ldap', 'password'))->toBe('hunter2');
})->with('secret_store_drivers');

it('returns null for a value that does not exist', function (string $driver) {
    [$store] = makeSecretStore($driver);

    expect($store->get('server/1/ldap', 'missing'))->toBeNull();
})->with('secret_store_drivers');

it('reports whether a key exists', function (string $driver) {
    [$store] = makeSecretStore($driver);

    expect($store->has('server/1/ldap', 'password'))->toBeFalse();

    $store->put('server/1/ldap', 'password', 'hunter2');

    expect($store->has('server/1/ldap', 'password'))->toBeTrue();
})->with('secret_store_drivers');

it('deletes a value', function (string $driver) {
    [$store] = makeSecretStore($driver);

    $store->put('server/1/ldap', 'password', 'hunter2');
    $store->delete('server/1/ldap', 'password');

    expect($store->has('server/1/ldap', 'password'))->toBeFalse();
})->with('secret_store_drivers');

it('generates and persists a value once via getOrCreate', function (string $driver) {
    [$store] = makeSecretStore($driver);

    $calls = 0;
    $generator = function () use (&$calls) {
        $calls++;

        return 'generated-value';
    };

    $first = $store->getOrCreate('server/1/db', 'password', $generator);
    $second = $store->getOrCreate('server/1/db', 'password', $generator);

    expect($first)->toBe('generated-value');
    expect($second)->toBe('generated-value');
    expect($calls)->toBe(1);
})->with('secret_store_drivers');

it('keeps multiple keys under the same path independent', function (string $driver) {
    [$store] = makeSecretStore($driver);

    $store->put('server/1/ldap', 'username', 'admin');
    $store->put('server/1/ldap', 'password', 'hunter2');

    expect($store->get('server/1/ldap', 'username'))->toBe('admin');
    expect($store->get('server/1/ldap', 'password'))->toBe('hunter2');

    $store->delete('server/1/ldap', 'username');

    expect($store->has('server/1/ldap', 'username'))->toBeFalse();
    expect($store->get('server/1/ldap', 'password'))->toBe('hunter2');
})->with('secret_store_drivers');

it('reports a successful test connection', function (string $driver) {
    [$store] = makeSecretStore($driver);

    expect($store->testConnection())->toBeTrue();
})->with('secret_store_drivers');
