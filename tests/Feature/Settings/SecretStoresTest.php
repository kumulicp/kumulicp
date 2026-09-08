<?php

use App\SecretStore;
use App\Server;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();

    $this->support = $support;

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
});

it('creates a database-driver secret store', function () {
    $response = $this->post('/admin/settings/secret-stores', [
        'name' => 'Local Database',
        'driver' => 'database',
    ]);

    $response->assertSessionDoesntHaveErrors();

    $store = SecretStore::where('name', 'Local Database')->firstOrFail();

    expect($store->driver)->toBe('database');
});

it('creates an openbao-driver secret store', function () {
    $response = $this->post('/admin/settings/secret-stores', [
        'name' => 'Org OpenBao',
        'driver' => 'openbao',
        'address' => 'https://openbao.example.com',
        'role_id' => 'role-id',
        'secret_id' => 'secret-id',
        'mount_path' => 'secret',
    ]);

    $response->assertSessionDoesntHaveErrors();

    $store = SecretStore::where('name', 'Org OpenBao')->firstOrFail();

    expect($store->driver)->toBe('openbao');
    expect($store->address)->toBe('https://openbao.example.com');
    expect($store->role_id)->toBe('role-id');
});

it('requires connection details for the openbao driver', function () {
    $response = $this->post('/admin/settings/secret-stores', [
        'name' => 'Org OpenBao',
        'driver' => 'openbao',
    ]);

    $response->assertSessionHasErrors(['address', 'role_id', 'secret_id']);
});

it('requires a unique name', function () {
    SecretStore::factory()->create(['name' => 'existing']);

    $response = $this->post('/admin/settings/secret-stores', [
        'name' => 'existing',
        'driver' => 'database',
    ]);

    $response->assertSessionHasErrors('name');
});

it('deletes a secret store that is not in use', function () {
    $store = SecretStore::factory()->create();

    $response = $this->delete('/admin/settings/secret-stores/'.$store->id);

    $response->assertSessionDoesntHaveErrors();
    expect(SecretStore::find($store->id))->toBeNull();
});

it('prevents deleting the default secret store', function () {
    $default = SecretStore::default();

    $response = $this->delete('/admin/settings/secret-stores/'.$default->id);

    $response->assertSessionHas('error');
    expect(SecretStore::find($default->id))->not->toBeNull();
});

it('prevents deleting a secret store assigned to a server', function () {
    $store = SecretStore::factory()->create();
    Server::factory()->create(['secret_store_id' => $store->id]);

    $response = $this->delete('/admin/settings/secret-stores/'.$store->id);

    $response->assertSessionHas('error');
    expect(SecretStore::find($store->id))->not->toBeNull();
});

it('keeps an existing AppRole secret id when the field is left blank on update', function () {
    $store = SecretStore::factory()->openbao()->create(['secret_id' => 'original-secret-id']);

    $response = $this->put('/admin/settings/secret-stores/'.$store->id, [
        'name' => $store->name,
        'driver' => 'openbao',
        'address' => $store->address,
        'role_id' => $store->role_id,
        'secret_id' => '',
        'mount_path' => $store->mount_path,
    ]);

    $response->assertSessionDoesntHaveErrors();

    expect($store->fresh()->secret_id)->toBe('original-secret-id');
});
