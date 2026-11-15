<?php

use App\Notifications\NewOrganizationSubscription;
use App\Organization;
use App\Services\Billing\SubscriptionRequestBuilder;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization as OrganizationFacade;
use App\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Billing\RecordingSubscriptionGateway;
use Tests\Support\TestSupports;

/**
 * The demo organization on a paid package plan, the way StripePricingTest sets it up.
 */
function paidPackageOrganization(): Organization
{
    setupAccountManagerDriver('db');
    $support = new TestSupports;
    $support->seed();
    $admin = User::where('username', 'demo')->firstOrFail();
    test()->actingAs($admin);

    $base_plan = $support->basePlan1OfType('package');
    $base_plan->payment_enabled = true;
    $base_plan->save();

    $support->setSubscription($admin->organization, $base_plan);
    OrganizationFacade::setOrganization($admin->organization);

    return $admin->organization;
}

beforeEach(function () {
    RecordingSubscriptionGateway::reset();
});

describe('SubscriptionRequestBuilder', function () {
    it('builds one line per price id for what the organization pays for', function () {
        $organization = paidPackageOrganization();

        $request = app(SubscriptionRequestBuilder::class)->build($organization);

        expect($request->organization->is($organization))->toBeTrue();

        $base = $request->lines->firstWhere('externalId', 'stripe_base');
        expect($base)->not->toBeNull();
        expect($base->quantity)->toBe(1);
        expect($base->component)->toBe('base');

        $ids = $request->lines->pluck('externalId')->all();
        expect($ids)->not->toContain('stripe_basic');
        expect($ids)->not->toContain('stripe_email');
        expect($ids)->not->toContain('stripe_storage');
    });

    it('gives the first subscription a trial of the configured length', function () {
        config(['billing.trial_days' => 14]);
        $organization = paidPackageOrganization();
        $organization->updateSetting('step', 1);
        $organization->save();

        $request = app(SubscriptionRequestBuilder::class)->build($organization);

        expect($request->firstSubscription)->toBeTrue();
        expect($request->trialDays)->toBe(14);
    });

    it('gives no trial once the organization is past its first step', function () {
        $organization = paidPackageOrganization();
        $organization->updateSetting('step', 4);
        $organization->save();

        $request = app(SubscriptionRequestBuilder::class)->build($organization);

        expect($request->firstSubscription)->toBeFalse();
        expect($request->trialDays)->toBe(0);
    });

    it('carries the organization discount code', function () {
        $organization = paidPackageOrganization();
        $organization->discount_id = 'coupon_10';
        $organization->save();

        expect(app(SubscriptionRequestBuilder::class)->build($organization)->discountId)->toBe('coupon_10');
    });
});

describe('BillingService::update', function () {
    beforeEach(function () {
        Billing::register('recording', RecordingSubscriptionGateway::class);
    });

    it('hands the driver the lines it should bill', function () {
        $organization = paidPackageOrganization();
        config(['billing.default' => 'recording']);

        Billing::update();

        expect(RecordingSubscriptionGateway::$requests)->toHaveCount(1);
        $request = RecordingSubscriptionGateway::$requests[0];
        expect($request->organization->is($organization))->toBeTrue();
        expect($request->lines->pluck('externalId')->all())->toContain('stripe_base');
    });

    it('does nothing when no billing driver is configured', function () {
        paidPackageOrganization();
        config(['billing.default' => null]);

        Billing::update();

        expect(RecordingSubscriptionGateway::$requests)->toBe([]);
    });

    it('does not bill a deactivated organization', function () {
        $organization = paidPackageOrganization();
        config(['billing.default' => 'recording']);
        $organization->status = 'deactivated';
        $organization->save();

        Billing::update();

        expect(RecordingSubscriptionGateway::$requests)->toBe([]);
    });

    it('tells the superaccount admins when the first subscription is created', function () {
        $organization = paidPackageOrganization();
        $organization->updateSetting('step', 1);
        $organization->save();
        config(['billing.default' => 'recording']);
        RecordingSubscriptionGateway::$creates = true;
        Notification::fake();

        Billing::update();

        $superaccount = Organization::where('type', 'superaccount')->first();
        Notification::assertSentTimes(NewOrganizationSubscription::class, count($superaccount->admins()));
    });

    it('stays quiet when the driver only changed an existing subscription', function () {
        $organization = paidPackageOrganization();
        $organization->updateSetting('step', 1);
        $organization->save();
        config(['billing.default' => 'recording']);
        RecordingSubscriptionGateway::$creates = false;
        Notification::fake();

        Billing::update();

        Notification::assertNothingSent();
    });

    it('stays quiet when a subscription is created after the first step', function () {
        $organization = paidPackageOrganization();
        $organization->updateSetting('step', 4);
        $organization->save();
        config(['billing.default' => 'recording']);
        RecordingSubscriptionGateway::$creates = true;
        Notification::fake();

        Billing::update();

        Notification::assertNothingSent();
    });
});

describe('a driver without payment methods in the app', function () {
    beforeEach(function () {
        Billing::register('recording', RecordingSubscriptionGateway::class);
        config(['billing.default' => 'recording']);
    });

    it('is treated as having nothing to collect', function () {
        expect(Billing::supportsPaymentMethods())->toBeFalse();
        expect(Billing::component())->toBe('');
        expect(Billing::hasDefaultPaymentMethod())->toBeTrue();
        expect(Billing::defaultPaymentMethod())->toBe([]);
        expect(Billing::defaultPaymentMethodBrandImage())->toBeNull();
        expect(Billing::intent())->toBe([]);
    });

    it('still answers the subscription questions itself', function () {
        expect(Billing::isBillable())->toBeTrue();
        expect(Billing::status())->toBe('active');
    });
});

it('reports payment method support for a driver with a card form', function () {
    setupBillingDriver('fake');

    expect(Billing::supportsPaymentMethods())->toBeTrue();
    expect(Billing::component())->toBe('FakePaymentMethod');
});
