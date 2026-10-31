<?php

use App\Application;
use App\AppRole;
use App\Enums\AccessType;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->demoApp = Application::where('slug', 'demo_app')->firstOrFail();
});

function roleUpdatePayload(AppRole $role, array $overrides = []): array
{
    return array_merge([
        'label' => $role->label ?? 'Role',
        'sub_name' => $role->name ?? 'Role',
        'slug' => $role->slug,
        'description' => 'A role',
        'category' => $role->category ?? 'Users',
        'access_type' => 'standard',
        'ignore_role' => false,
        'required_features' => [],
        'implied_roles' => [],
    ], $overrides);
}

it('accepts every access type the enum supports when updating a role', function (string $access_type) {
    $role = $this->demoApp->roles()->firstOrFail();

    $this->actingAs($this->user)
        ->post("/admin/apps/{$this->demoApp->slug}/roles/{$role->id}", roleUpdatePayload($role, ['access_type' => $access_type]))
        ->assertSessionHasNoErrors();

    expect($role->fresh()->access_type)->toBe(AccessType::from($access_type));
})->with(['standard', 'basic', 'minimal', 'none']);

it('rejects an access type that is not in the enum when updating a role', function (string $access_type) {
    $role = $this->demoApp->roles()->firstOrFail();
    $original = $role->access_type;

    $this->actingAs($this->user)
        ->post("/admin/apps/{$this->demoApp->slug}/roles/{$role->id}", roleUpdatePayload($role, ['access_type' => $access_type]))
        ->assertSessionHasErrors('access_type');

    expect($role->fresh()->access_type)->toBe($original);
})->with(['disabled', 'volunteer', '']);

it('rejects an unknown access type when creating a role', function () {
    $this->actingAs($this->user)
        ->post("/admin/apps/{$this->demoApp->slug}/roles", [
            'label' => 'New role',
            'sub_name' => 'New role',
            'slug' => 'new_role',
            'description' => 'A new role',
            'category' => 'Users',
            'access_type' => 'volunteer',
        ])
        ->assertSessionHasErrors('access_type');

    expect($this->demoApp->roles()->where('slug', 'new_role')->exists())->toBeFalse();
});
