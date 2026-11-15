<?php

use App\Enums\AccessType;
use App\Organization;
use App\Plan;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\Organization as OrganizationFacade;

// The access types are named by the organization's plan, and fall back to defaults

function labelsService(array $settings): BasePlanService
{
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);

    return new BasePlanService($organization, Plan::factory()->create(['type' => 'package', 'settings' => $settings]));
}

it('names access types with defaults when the plan doesn\'t', function () {
    $service = labelsService([]);

    expect($service->accessTypeLabel(AccessType::STANDARD))->toBe('Full User')
        ->and($service->accessTypeLabel(AccessType::BASIC))->toBe('Volunteer')
        ->and($service->accessTypeLabel(AccessType::MINIMAL))->toBe('Constituent')
        ->and($service->accessTypeLabel(AccessType::NONE))->toBe('No access');
});

it('names access types as the plan does', function () {
    $service = labelsService([
        'base' => ['standard_label' => 'Staff', 'minimal_label' => 'Member'],
        'basic' => ['name' => 'Helper'],
    ]);

    expect($service->accessTypeLabel(AccessType::STANDARD))->toBe('Staff')
        ->and($service->accessTypeLabel(AccessType::BASIC))->toBe('Helper')
        ->and($service->accessTypeLabel(AccessType::MINIMAL))->toBe('Member');
});

it('only offers the basic and minimal access types the plan has turned on', function () {
    $off = labelsService(['basic' => ['enabled' => false], 'base' => ['minimal_enabled' => false]]);
    $on = labelsService(['basic' => ['enabled' => true], 'base' => ['minimal_enabled' => true]]);

    expect(collect($off->availableAccessTypes())->pluck('value')->all())->toBe(['standard', 'none'])
        ->and(collect($on->availableAccessTypes())->pluck('value')->all())->toBe(['standard', 'basic', 'minimal', 'none'])
        ->and(collect($on->availableAccessTypes())->pluck('text')->all())->toBe(['Full User', 'Volunteer', 'Constituent', 'No access']);
});

it('keeps offering the access types of plans saved before they could be turned on, when they were named', function () {
    $named = labelsService(['basic' => ['name' => 'Guests'], 'base' => ['minimal_label' => 'Constituent']]);
    $unnamed = labelsService(['basic' => ['name' => null], 'base' => ['minimal_label' => null]]);

    expect($named->isBasicUsersEnabled())->toBeTrue()
        ->and($named->isMinimalUsersEnabled())->toBeTrue()
        ->and($unnamed->isBasicUsersEnabled())->toBeFalse()
        ->and($unnamed->isMinimalUsersEnabled())->toBeFalse();
});
