<?php

namespace App\Services\Organization;

use App\AppInstance;
use App\Enums\AccessType;
use App\Organization;
use App\Services\SubscriptionService;
use Illuminate\Auth\Access\Response;

/**
 * Whether an organization may take on another user: it is not deactivated and its plan has not run out
 * of the kind of user they would be. One rule for every way a user is added, so that adding them by hand
 * and having them register by themselves cannot disagree.
 *
 * It takes the organization it is asked about. Registration happens without anyone signed in, so the
 * plan cannot be the one of whoever is using the panel.
 */
class UserAllowance
{
    /** Higher is the bigger seat: a user is as much as their biggest role. */
    private const RANK = ['none' => 0, 'minimal' => 1, 'basic' => 2, 'standard' => 3];

    private ?SubscriptionService $subscription = null;

    public function __construct(
        private Organization $organization,
        private ?BasePlanService $plan = null,
    ) {}

    /**
     * Adding a user by hand: the user is given the default roles of every app afterwards, so this only
     * asks for the biggest seat, a standard one.
     */
    public function allowed(): bool
    {
        if ($this->organization->status === 'deactivated') {
            return false;
        }

        return ! $this->basePlan()->isMax('standard');
    }

    /**
     * Someone who will be given the default user roles of these apps and nothing else, as people
     * registering by themselves are. Only the seat they take counts: where the roles are basic or
     * minimal (an app's constituents or members, say), a full standard tier does not stop them.
     *
     * @param  iterable<int, AppInstance>  $apps
     */
    public function allowedFor(iterable $apps): bool
    {
        if ($this->organization->status === 'deactivated') {
            return false;
        }

        return match ($this->accessTypeFor($apps)) {
            AccessType::STANDARD => ! $this->basePlan()->isMax('standard'),
            AccessType::BASIC => ! $this->basePlan()->isMax('basic'),
            default => true,
        };
    }

    /**
     * The seat the default user roles of these apps give: the biggest of them, each as the plan that
     * counts it names it (a plan without basic users counts a basic role as standard).
     *
     * @param  iterable<int, AppInstance>  $apps
     */
    public function accessTypeFor(iterable $apps): AccessType
    {
        $highest = AccessType::NONE;

        foreach ($apps as $app) {
            // One plan for the whole organization, or one for each app, as the organization pays
            $plan = $this->organization->plan?->type === 'package' ? $this->basePlan() : $this->subscription()->app_instance($app);

            foreach ($app->version?->defaultUserRoles() ?? [] as $role) {
                $type = $role->accessType($plan, $app);

                if (self::RANK[$type->value] > self::RANK[$highest->value]) {
                    $highest = $type;
                }
            }
        }

        return $highest;
    }

    public function check(): Response
    {
        return $this->allowed() ? Response::allow() : Response::deny(__('organization.user.denied.add'));
    }

    private function basePlan(): BasePlanService
    {
        return $this->plan ??= $this->subscription()->base();
    }

    private function subscription(): SubscriptionService
    {
        return $this->subscription ??= new SubscriptionService($this->organization);
    }
}
