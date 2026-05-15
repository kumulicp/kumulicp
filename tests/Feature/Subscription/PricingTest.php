<?php

use App\Enums\PlanEntity;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
        $this->markTestSkipped('Requires LDAP driver');
    }
});

it('reflects base pricing change', function () {
    $this->withoutExceptionHandling();
    $this->followingRedirects();

    $this->support->base_1->payment_enabled = true;
    $this->support->base_1->save();

    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

    $base_pricing = Subscription::refresh()->base();

    expect($base_pricing->optionStats('base')['total_price'])->toEqual(1.00);
    expect($base_pricing->optionStats('standard')['total_price'])->toEqual(1.00);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(0.00);
    expect($base_pricing->optionStats('storage')['total_price'])->toEqual(0.00);
});

it('recalculates pricing when adding standard users', function () {
    $this->withoutExceptionHandling();

    $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

    $base_pricing = Subscription::base();
    expect($base_pricing->optionStats('standard')['total_price'])->toEqual(2.00);

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);
    expect($base_pricing->optionStats('standard')['total_price'])->toEqual(4.00);
});

it('recalculates pricing when adding basic users', function () {
    $this->withoutExceptionHandling();
    $this->followingRedirects();

    $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

    $base_pricing = Subscription::all()->base();
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(0.00);

    grantPermission('testing1', $this->demoApp->id, ['basic_demo_role']);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(2.00);

    grantPermission('testing2', $this->demoApp->id, ['basic_demo_role']);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(4.00);

    $this->post('/users', [
        'username' => 'testing3',
        'first_name' => 'test',
        'last_name' => 'user3',
        'personal_email' => 'test3@example.com',
    ]);

    grantPermission('testing3', $this->demoApp->id, ['basic_demo_role']);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(4.00);
});

it('recalculates pricing when adding additional storage', function () {
    Http::fake([
        'https://demo-nextcloud.example.com:443/ocs/v1.php/cloud/users/testing1' => ['hey' => 'there'],
    ]);

    $this->withoutExceptionHandling();
    Organization::setOrganization($this->user->organization);
    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);
    setAdditionalStorage('testing1', $this->demoApp->id, 1);

    $app_pricing = Subscription::app_instance($this->demoApp);
    expect($app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price'])->toEqual(2.00);
    expect(AccountManager::users()->find('testing1')->appStorage($this->demoApp))->toEqual(4);

    grantPermission('testing2', $this->demoApp->id, ['demo_role']);
    setAdditionalStorage('testing2', $this->demoApp->id, 1);

    expect(AccountManager::users()->find('testing2')->appStorage($this->demoApp))->toEqual(4);
    expect($app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price'])->toEqual(4.00);
});

it('compiles stripe pricing correctly', function () {
    $this->withoutExceptionHandling();
    $this->followingRedirects();

    $this->support->base_1->payment_enabled = true;
    $this->support->base_1->save();

    $this->support->setSubscription($this->user->organization, $this->support->base_1);
    $base_pricing = Subscription::refresh()->base();
    $stripe = $base_pricing->stripePricing();

    expect(Arr::get($stripe, 'stripe_base.quantity'))->toEqual(1);
    expect(Arr::get($stripe, 'stripe_basic.quantity'))->toEqual(0);
    expect(Arr::get($stripe, 'stripe_email.quantity'))->toEqual(0);
    expect(Arr::get($stripe, 'stripe_storage.quantity'))->toEqual(0);
    expect(Arr::get($stripe, 'stripe_standard.quantity'))->toEqual(1);
    expect(Arr::get($stripe, 'stripe_application.quantity'))->toEqual(1);
});
