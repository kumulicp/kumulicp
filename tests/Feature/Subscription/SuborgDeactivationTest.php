<?php

/*
|--------------------------------------------------------------------------
| Deactivating and reactivating suborganizations
|--------------------------------------------------------------------------
|
| Suborganizations can be deactivated but never deleted (see
| docs/suborganizations-production-plan.md, section 4). These tests cover the
| cancel and resubscribe endpoints, which today have gaps:
|
|  - cancel has no server-side authorization, the 'unsubscribe' gate is only
|    used to decide whether to show the button;
|  - resubscribe ignores the {organization} in its route and always acts on the
|    logged-in user's own organization and all of its children.
|
| Feature/Subscription's global beforeEach (tests/Pest.php) seeds org 1, adds an
| active demo app and acts as the "demo" user ($this->user, $this->demoApp).
|
*/

use App\Organization;
use App\User;
use Illuminate\Support\Facades\Queue;

function suborgDeactivationMakeSuborg($test, string $status = 'active'): Organization
{
    return Organization::factory()->create([
        'plan_id' => $test->support->base_1->id,
        'parent_organization_id' => 1,
        'type' => Organization::find(1)->type,
        'settings' => ['step' => 4, 'include_in_parent_invoice' => true],
        'status' => $status,
    ]);
}

it('lets a parent cancel one of its suborganizations', function () {
    Queue::fake();
    $suborg = suborgDeactivationMakeSuborg($this);

    $this->delete('/subscription/'.$suborg->id);

    expect($suborg->fresh()->status)->toBe('deactivating')
        ->and(Organization::find(1)->status)->toBe('active');
});

it('does not let a user cancel an unrelated organization', function () {
    Queue::fake();
    $other = Organization::factory()->create([
        'plan_id' => $this->support->base_1->id,
        'type' => Organization::find(1)->type,
        'status' => 'active',
    ]);

    $this->delete('/subscription/'.$other->id)->assertForbidden();

    expect($other->fresh()->status)->toBe('active');
});

it('does not let a suborganization cancel its parent', function () {
    Queue::fake();
    $suborg = suborgDeactivationMakeSuborg($this);
    $suborg_user = User::factory()->create([
        'organization_id' => $suborg->id,
        'email_verified_at' => now(),
    ]);
    $this->actingAs($suborg_user);

    $this->delete('/subscription/1')->assertForbidden();

    expect(Organization::find(1)->status)->toBe('active');
});

it('resubscribes only the suborganization it was asked to, not the parent', function () {
    Queue::fake();
    $suborg = suborgDeactivationMakeSuborg($this, 'deactivating');

    // The parent has an app that is winding down. Resubscribing the suborg
    // must not bring it back.
    $this->demoApp->forceFill([
        'status' => 'deactivating',
        'deactivate_at' => now()->addDays(5),
    ])->save();

    $this->post('/subscription/'.$suborg->id.'/resubscribe');

    expect($suborg->fresh()->status)->toBe('active')
        ->and($this->demoApp->fresh()->status)->toBe('deactivating');
});

it('does not let a user resubscribe an unrelated organization', function () {
    Queue::fake();
    $other = Organization::factory()->create([
        'plan_id' => $this->support->base_1->id,
        'type' => Organization::find(1)->type,
        'status' => 'deactivating',
    ]);

    $this->post('/subscription/'.$other->id.'/resubscribe')->assertForbidden();

    expect($other->fresh()->status)->toBe('deactivating');
});
