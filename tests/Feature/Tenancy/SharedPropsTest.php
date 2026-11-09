<?php

use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('tells the frontend which mode is running', function (bool $single) {
    (new TestSupports)->seed();
    $single ? useSingleTenancy() : useMultiTenancy();

    $this->actingAs(User::where('username', 'demo')->firstOrFail())
        ->get('/settings/organization')
        ->assertInertia(fn ($page) => $page->where('tenancy.single', $single));
})->with([true, false]);
