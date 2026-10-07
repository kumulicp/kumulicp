<?php

use App\Organization;
use App\Support\Facades\AccountManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    (new TestSupports)->seed();

    DB::table('countries')->insert([
        ['id' => 1, 'iso2' => 'CA', 'name' => 'Canada', 'status' => 1, 'phone_code' => '1', 'iso3' => 'CAN', 'region' => 'Americas', 'subregion' => 'Northern America'],
        ['id' => 2, 'iso2' => 'US', 'name' => 'United States', 'status' => 1, 'phone_code' => '1', 'iso3' => 'USA', 'region' => 'Americas', 'subregion' => 'Northern America'],
    ]);
    DB::table('states')->insert([
        ['country_id' => 1, 'name' => 'Ontario', 'country_code' => 'CA', 'state_code' => 'ON'],
        ['country_id' => 1, 'name' => 'Alberta', 'country_code' => 'CA', 'state_code' => 'AB'],
        ['country_id' => 2, 'name' => 'Texas', 'country_code' => 'US', 'state_code' => 'TX'],
    ]);

    $userInterface = AccountManager::users(Organization::find(1))->add([
        'username' => 'locationsuser',
        'first_name' => 'Test',
        'last_name' => 'User',
        'name' => 'Test User',
        'email' => 'locations@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);
    $userInterface->permissions()->addControlPanelAccess();
    $user = $userInterface->databaseUser();
    $user->email_verified_at = now();
    $user->save();
    $this->user = $user;
});

it('requires authentication', function () {
    $this->get('/locations/countries')->assertRedirect();
    $this->get('/locations/countries/CA/states')->assertRedirect();
});

it('lists countries as value/text options', function () {
    $this->actingAs($this->user)->get('/locations/countries')
        ->assertOk()
        ->assertExactJson([
            ['value' => 'CA', 'text' => 'Canada'],
            ['value' => 'US', 'text' => 'United States'],
        ]);
});

it('lists the states of a country by state code', function () {
    $this->actingAs($this->user)->get('/locations/countries/ca/states')
        ->assertOk()
        ->assertExactJson([
            ['value' => 'AB', 'text' => 'Alberta'],
            ['value' => 'ON', 'text' => 'Ontario'],
        ]);
});

it('returns no states for an unknown country', function () {
    $this->actingAs($this->user)->get('/locations/countries/ZZ/states')
        ->assertOk()
        ->assertExactJson([]);
});

it('rejects malformed country codes', function () {
    $this->actingAs($this->user)->get('/locations/countries/CAN/states')->assertNotFound();
});
