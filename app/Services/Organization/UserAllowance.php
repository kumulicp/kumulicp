<?php

namespace App\Services\Organization;

use App\Organization;
use App\Services\SubscriptionService;
use Illuminate\Auth\Access\Response;

/**
 * Whether an organization may take on another user: it is not deactivated and its plan has not run out
 * of standard users. One rule for every way a user is added, so that adding them by hand and having
 * them register by themselves cannot disagree.
 *
 * It takes the organization it is asked about. Registration happens without anyone signed in, so the
 * plan cannot be the one of whoever is using the panel.
 */
class UserAllowance
{
    public function __construct(
        private Organization $organization,
        private ?BasePlanService $plan = null,
    ) {}

    public function allowed(): bool
    {
        if ($this->organization->status === 'deactivated') {
            return false;
        }

        $plan = $this->plan ?? (new SubscriptionService($this->organization))->base();

        return ! $plan->isMax('standard');
    }

    public function check(): Response
    {
        return $this->allowed() ? Response::allow() : Response::deny(__('organization.user.denied.add'));
    }
}
