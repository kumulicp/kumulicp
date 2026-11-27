<?php

namespace App\Services\Organization;

use App\Organization;
use App\Services\SubscriptionService;
use App\Support\Facades\Billing;
use App\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Collection;

/**
 * Who an app is activated for, and what that means: which plan limits apply, who may do it and who pays.
 *
 * A parent organization can activate apps for itself and for its suborganizations, so the organization an
 * activation is for (the target) is not always the one the user belongs to. Everything about an activation
 * is worked out from the target, in one place, so the screens that offer it and the gates and actions that
 * allow it cannot disagree:
 *
 *  - Limits come from the target's own plan. Being billed with the parent decides who pays, not what is allowed.
 *  - A suborganization billed on its parent's invoice is activated for by the parent. Its own users can do it
 *    only when the parent has allowed that ('allow_app_activation'), and the parent still pays.
 *  - A suborganization billed separately is activated for only by its own users, who pay with their own
 *    payment method.
 */
class AppActivationContext
{
    public function __construct(
        public readonly User $user,
        public readonly Organization $target,
    ) {}

    /**
     * The organizations the user could activate an app for: their own and the suborganizations they may
     * activate for (the ones their organization pays for).
     *
     * @return Collection<int, Organization>
     */
    public static function organizationsFor(User $user): Collection
    {
        return collect([$user->organization])->merge(
            $user->organization->suborganizations()->notDeactivated()->get()
                ->filter(fn (Organization $suborganization) => (new self($user, $suborganization))->authorize()->allowed())
        )->values();
    }

    /**
     * The organization the user is in, or one of its suborganizations.
     */
    public function isWithinUsersFamily(): bool
    {
        $own = $this->user->organization;

        return $this->target->is($own) || $this->target->parent_organization_id === $own->id;
    }

    public function isSuborganization(): bool
    {
        return (bool) $this->target->parent_organization_id;
    }

    /**
     * Whether the target is a suborganization that is billed on its parent's invoice.
     */
    public function isBilledWithParent(): bool
    {
        return $this->isSuborganization() && $this->target->setting('include_in_parent_invoice') === true;
    }

    /**
     * The organization whose payment method pays for what is activated.
     */
    public function payer(): Organization
    {
        return $this->isBilledWithParent() ? $this->target->parent_organization : $this->target;
    }

    public function payerIsUsersOrganization(): bool
    {
        return $this->payer()->is($this->user->organization);
    }

    /**
     * The target's own plan, with the target's own apps counted against it.
     */
    public function basePlan(): BasePlanService
    {
        return (new SubscriptionService($this->target))->base();
    }

    /**
     * Whether the payer can pay for a plan that takes payment.
     */
    public function payerCanPay(): bool
    {
        return Billing::organization($this->payer())->hasDefaultPaymentMethod();
    }

    /**
     * Whether the user may activate apps for the target.
     */
    public function authorize(): Response
    {
        if (! $this->isWithinUsersFamily()) {
            return Response::deny(__('organization.app.denied.target'), 'invalid_target');
        }

        if ($this->target->is($this->user->organization)) {
            // A suborganization's own users, when its parent pays: only if the parent allows it
            if ($this->isBilledWithParent() && ! $this->target->setting('allow_app_activation')) {
                return Response::deny(__('organization.app.denied.parent_manages'), 'parent_manages');
            }

            return Response::allow();
        }

        // The parent, for one of its suborganizations: only when the parent is the one who pays
        return $this->isBilledWithParent()
            ? Response::allow()
            : Response::deny(__('organization.app.denied.own_billing'), 'suborganization_billed_separately');
    }
}
