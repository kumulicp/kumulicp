<?php

use App\Organization;
use App\User;
use Illuminate\Support\Facades\Config;
use Tests\Support\TestSupports;

beforeEach(function () {
    $this->support = new TestSupports;
    $this->support->seed();

    Config::set('toggle.flags.sub-organizations', true);

    $this->organization = Organization::find(1);
    $this->organization->plan->updateSettings(['suborganizations.enabled' => true]);
    $this->organization->plan->save();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
});

it('lists suborganizations', function () {
    $this->get('/settings/suborganizations')->assertSuccessful();
});

it('adds a suborganization', function () {
    $response = $this->post('/settings/suborganizations', [
        'subdomain' => 'subtest',
        'name' => 'Sub Test',
        'description' => 'A test suborganization',
        'email' => 'subtest@example.com',
        'phone_number' => '123-456-7890',
        'street' => '123 Sub St',
        'zipcode' => '12345',
        'city' => 'Subtown',
        'state' => 'AZ',
        'country' => 'US',
        'type' => 'business',
        'terms_of_use' => true,
    ]);

    $response->assertSessionHasNoErrors();

    $suborganization = Organization::where('slug', 'subtest')->first();

    expect($suborganization)->not->toBeNull();
    expect($suborganization->parent_organization_id)->toBe($this->organization->id);
    expect($suborganization->name)->toBe('Sub Test');

    $response->assertRedirect('/settings/suborganizations/'.$suborganization->id);
});

it('validates required fields when adding a suborganization', function () {
    $response = $this->post('/settings/suborganizations', []);

    $response->assertSessionHasErrors([
        'subdomain', 'name', 'description', 'email', 'phone_number',
        'street', 'zipcode', 'city', 'state', 'country', 'type', 'terms_of_use',
    ]);

    expect(Organization::where('parent_organization_id', $this->organization->id)->count())->toBe(0);
});

it('rejects a duplicate subdomain', function () {
    $existing = Organization::factory()->create([
        'slug' => 'subtest',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);

    $response = $this->post('/settings/suborganizations', [
        'subdomain' => 'subtest',
        'name' => 'Sub Test',
        'description' => 'A test suborganization',
        'email' => 'subtest@example.com',
        'phone_number' => '123-456-7890',
        'street' => '123 Sub St',
        'zipcode' => '12345',
        'city' => 'Subtown',
        'state' => 'AZ',
        'country' => 'US',
        'type' => 'business',
        'terms_of_use' => true,
    ]);

    $response->assertSessionHasErrors('subdomain');
});

it('edits a suborganization', function () {
    $suborganization = Organization::factory()->create([
        'slug' => 'subedit',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);

    $this->get('/settings/suborganizations/'.$suborganization->id)
        ->assertSuccessful();
});

it('updates a suborganization', function () {
    $suborganization = Organization::factory()->create([
        'slug' => 'subupdate',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);

    $this->followingRedirects();

    $response = $this->put('/settings/suborganizations/'.$suborganization->id, [
        'name' => 'Updated Name',
        'description' => 'Updated description',
        'org_email' => 'updated@example.com',
        'org_phone_number' => '111-222-3333',
        'street' => '456 New St',
        'zipcode' => '54321',
        'city' => 'Newtown',
        'state' => 'CA',
        'country' => 'US',
        'include_in_parent_invoice' => true,
        'user_first_name' => $suborganization->contact_first_name,
        'user_last_name' => $suborganization->contact_last_name,
        'user_phone_number' => $suborganization->contact_phone_number,
        'user_email' => $suborganization->contact_email,
    ]);

    $response->assertSuccessful();

    $suborganization->refresh();
    expect($suborganization->name)->toBe('Updated Name');
    expect($suborganization->description)->toBe('Updated description');
    expect($suborganization->email)->toBe('updated@example.com');
    expect($suborganization->phone_number)->toBe('111-222-3333');
    expect($suborganization->street)->toBe('456 New St');
    expect($suborganization->zipcode)->toBe('54321');
    expect($suborganization->city)->toBe('Newtown');
    expect($suborganization->state)->toBe('CA');
    expect($suborganization->country)->toBe('US');
});

it('validates required fields when updating a suborganization', function () {
    $suborganization = Organization::factory()->create([
        'slug' => 'subvalidate',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);

    $response = $this->put('/settings/suborganizations/'.$suborganization->id, []);

    $response->assertSessionHasErrors([
        'name', 'description', 'org_email', 'org_phone_number',
        'street', 'zipcode', 'city', 'state', 'country', 'include_in_parent_invoice',
    ]);
});

it('requires contact details when not including in parent invoice', function () {
    $suborganization = Organization::factory()->create([
        'slug' => 'subcontact',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);

    $response = $this->put('/settings/suborganizations/'.$suborganization->id, [
        'name' => 'Updated Name',
        'description' => 'Updated description',
        'org_email' => 'updated@example.com',
        'org_phone_number' => '111-222-3333',
        'street' => '456 New St',
        'zipcode' => '54321',
        'city' => 'Newtown',
        'state' => 'CA',
        'country' => 'US',
        'include_in_parent_invoice' => false,
    ]);

    $response->assertSessionHasErrors([
        'user_first_name', 'user_last_name', 'user_phone_number', 'user_email',
    ]);
});

it('does not allow editing an unrelated organization', function () {
    $other = Organization::factory()->create([
        'slug' => 'unrelated',
    ]);

    $this->get('/settings/suborganizations/'.$other->id)->assertForbidden();
});

it('does not allow access to suborganizations when the plan does not have it enabled', function () {
    $this->organization->plan->updateSettings(['suborganizations.enabled' => false]);
    $this->organization->plan->save();

    $this->get('/settings/suborganizations')->assertForbidden();
});

it('does not allow access to suborganizations when the toggle is disabled', function () {
    Config::set('toggle.flags.sub-organizations', false);

    $this->get('/settings/suborganizations')->assertNotFound();
});

it('does not currently support deleting a suborganization', function () {
    $suborganization = Organization::factory()->create([
        'slug' => 'subdelete',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);

    $response = $this->delete('/settings/suborganizations/'.$suborganization->id);

    $response->assertSuccessful();

    expect(Organization::find($suborganization->id))->not->toBeNull();
});
