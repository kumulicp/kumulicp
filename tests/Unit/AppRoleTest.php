<?php

use App\AppRole;
use App\Enums\AccessType;
use App\Services\Organization\BasePlanService;

function fakeAppRoleSubscription(array $available_access_types): BasePlanService
{
    return new class($available_access_types) extends BasePlanService
    {
        public function __construct(private array $available_access_types)
        {
        }

        public function availableAccessTypesList()
        {
            return $this->available_access_types;
        }
    };
}

it('keeps standard access type unchanged', function () {
    $role = new AppRole;
    $role->access_type = AccessType::STANDARD;

    expect($role->accessType(fakeAppRoleSubscription(['standard'])))->toBe(AccessType::STANDARD);
});

it('downgrades basic access type to standard when basic is unavailable', function () {
    $role = new AppRole;
    $role->access_type = AccessType::BASIC;

    expect($role->accessType(fakeAppRoleSubscription(['standard'])))->toBe(AccessType::STANDARD);
});

it('keeps basic access type when basic is available', function () {
    $role = new AppRole;
    $role->access_type = AccessType::BASIC;

    expect($role->accessType(fakeAppRoleSubscription(['standard', 'basic'])))->toBe(AccessType::BASIC);
});

it('downgrades minimal access type to standard when neither minimal nor basic is available', function () {
    $role = new AppRole;
    $role->access_type = AccessType::MINIMAL;

    expect($role->accessType(fakeAppRoleSubscription(['standard'])))->toBe(AccessType::STANDARD);
});

it('downgrades minimal access type to basic when minimal is unavailable but basic is available', function () {
    $role = new AppRole;
    $role->access_type = AccessType::MINIMAL;

    expect($role->accessType(fakeAppRoleSubscription(['standard', 'basic'])))->toBe(AccessType::BASIC);
});

it('keeps minimal access type when minimal is available', function () {
    $role = new AppRole;
    $role->access_type = AccessType::MINIMAL;

    expect($role->accessType(fakeAppRoleSubscription(['standard', 'basic', 'minimal'])))->toBe(AccessType::MINIMAL);
});
