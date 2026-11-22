<?php

namespace App\Updates;

use App\Organization;
use App\OrganizationUpdateState;
use App\Services\SubscriptionService;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\SubscriptionSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What an organization's subscription is out of date for: the counterpart of
 * UpdateDrift for the organization. Its aspects are what SubscriptionUpdate brings
 * up to date:
 *
 * - billing:      what the customer is charged and what the billing provider has to
 *                 be told (pricing, plan selection, status, discount, trial)
 * - access:       what the plans give the users (storage, access types, which apps
 *                 are active), and so the groups of its apps
 * - capabilities: whether the plan has domains and email, and their limits
 *
 * They are stored when a SubscriptionUpdate has settled, and compared with what
 * they work out now. A fourth row, `plans`, only remembers which plan each part of
 * the subscription was on, to say which it moved to another from.
 *
 * Like UpdateDrift, an aspect with nothing stored is unknown, not out of date.
 */
class OrganizationDrift
{
    public const BILLING = 'billing';

    public const ACCESS = 'access';

    public const CAPABILITIES = 'capabilities';

    /** What the plans were, kept to say what an organization moved from: never out of date itself */
    public const PLANS = 'plans';

    /** @var array<int, string> */
    public const ASPECTS = [self::BILLING, self::ACCESS, self::CAPABILITIES];

    /** Where SubscriptionUpdate used to keep what is now stored here; read once, until it is replaced */
    public const LEGACY_SETTING = 'subscription_snapshot';

    public function label(string $aspect): string
    {
        return __('admin.update_organization_aspects.'.$aspect);
    }

    /**
     * The organizations whose subscription is looked at: customers, not the shared
     * organization or one made for a test, and not one that has been deactivated.
     *
     * @return Builder<Organization>
     */
    public function scannable(): Builder
    {
        return Organization::where('type', '!=', 'shared')->notTest()->where('status', '!=', 'deactivated');
    }

    /**
     * The organization's subscription as it is now. Worked out as SubscriptionUpdate
     * does, with the organization as the account for it, and the account that was
     * there put back: this can run in the middle of an admin's request.
     */
    public function snapshot(Organization $organization): SubscriptionSnapshot
    {
        $account = OrganizationFacade::account();

        try {
            OrganizationFacade::setOrganization($organization);
            FastCache::clear(organization: $organization);

            return SubscriptionSnapshot::capture((new SubscriptionService($organization))->refresh());
        } finally {
            FastCache::clear(organization: $organization);

            if ($account) {
                OrganizationFacade::setOrganization($account);
            }
        }
    }

    /**
     * What the aspects work out now.
     *
     * @return array<string, string> fingerprints by aspect key
     */
    public function current(Organization $organization, ?SubscriptionSnapshot $snapshot = null): array
    {
        $snapshot ??= $this->snapshot($organization);

        return [
            self::BILLING => $snapshot->billing,
            self::ACCESS => $snapshot->access,
            self::CAPABILITIES => $this->capabilities($organization),
        ];
    }

    /**
     * What was stored when a SubscriptionUpdate last settled.
     *
     * @return Collection<string, OrganizationUpdateState> by aspect
     */
    public function states(Organization $organization): Collection
    {
        return OrganizationUpdateState::where('organization_id', $organization->id)->get()->keyBy('aspect');
    }

    /**
     * The aspects that changed since they were last brought up to date.
     *
     * @param  array<string, string>|null  $current  What they work out now, if that was just done
     * @return array<int, string>
     */
    public function drift(Organization $organization, ?array $current = null): array
    {
        $this->importLegacy($organization);
        $states = $this->states($organization);
        $current ??= $this->current($organization);

        return array_values(array_filter(
            array_keys($current),
            fn (string $aspect) => $states->has($aspect) && $states[$aspect]->fingerprint !== $current[$aspect]
        ));
    }

    /**
     * What the subscription was when it was last brought up to date, for
     * SubscriptionUpdate to tell what changed since. Null when it never was.
     */
    public function previous(Organization $organization): ?SubscriptionSnapshot
    {
        $states = $this->states($organization);

        if ($states->has(self::BILLING) && $states->has(self::ACCESS) && $states->has(self::PLANS)) {
            return new SubscriptionSnapshot((array) $states[self::PLANS]->details, $states[self::BILLING]->fingerprint, $states[self::ACCESS]->fingerprint);
        }

        return SubscriptionSnapshot::fromArray($organization->setting(self::LEGACY_SETTING));
    }

    /**
     * Stores what has been brought up to date.
     *
     * @param  array<int, string>|null  $only  Just these aspects; all of them, and the plans, if null
     */
    public function record(Organization $organization, SubscriptionSnapshot $snapshot, ?int $task_id = null, ?array $only = null): void
    {
        $fingerprints = $this->current($organization, $snapshot);

        $rows = [];
        foreach ($fingerprints as $aspect => $fingerprint) {
            if ($only === null || in_array($aspect, $only, true)) {
                $rows[$aspect] = ['fingerprint' => $fingerprint, 'details' => null];
            }
        }

        if ($only === null) {
            $rows[self::PLANS] = ['fingerprint' => Fingerprint::of($snapshot->plans), 'details' => $snapshot->plans];
        }

        foreach ($rows as $aspect => $row) {
            OrganizationUpdateState::updateOrCreate(
                ['organization_id' => $organization->id, 'aspect' => $aspect],
                [
                    'fingerprint' => $row['fingerprint'],
                    'current_fingerprint' => $row['fingerprint'],
                    'details' => $row['details'],
                    'applied_at' => now(),
                    'drift_detected_at' => null,
                    'definition_change_id' => null,
                    'dismissed_at' => null,
                    'dismissed_by_id' => null,
                    'apply_attempts' => 0,
                    'apply_attempted_at' => null,
                    'task_id' => $task_id,
                ],
            );
        }
    }

    /**
     * Compares what the aspects work out now with what was applied and notes the
     * result, so which organizations are out of date can be looked up without working
     * anything out. Changes nothing on the organization.
     *
     * @param  int|null  $change_id  The edit that made this worth looking at, if one did
     * @return array<int, string> the aspects that are out of date
     */
    public function refresh(Organization $organization, ?int $change_id = null): array
    {
        $this->importLegacy($organization);
        $states = $this->states($organization);

        $drifted = [];
        foreach ($this->current($organization) as $aspect => $fingerprint) {
            // Unknown: nothing to compare with
            if (! $state = $states->get($aspect)) {
                continue;
            }

            $state->current_fingerprint = $fingerprint;

            if ($state->fingerprint === $fingerprint) {
                $state->drift_detected_at = null;
                $state->definition_change_id = null;
            } else {
                $drifted[] = $aspect;

                // Out of date again after it was dismissed: the dismissal is about the earlier change
                if ($state->drift_detected_at === null) {
                    $state->dismissed_at = null;
                    $state->dismissed_by_id = null;
                }

                $state->drift_detected_at ??= now();
                $state->definition_change_id ??= $change_id;
            }

            $state->save();
        }

        return $drifted;
    }

    /**
     * Takes where the subscription stands as up to date, for an organization that
     * has nothing stored. Only changes from then on show.
     */
    public function baseline(Organization $organization): bool
    {
        $this->importLegacy($organization);

        if ($this->states($organization)->has(self::BILLING)) {
            return false;
        }

        $this->record($organization, $this->snapshot($organization));

        return true;
    }

    /**
     * Takes what is out of date as up to date without bringing it up to date: the
     * change needs nothing done. Remembers when and by whom.
     *
     * @param  array<int, string>|null  $only  Just these aspects
     */
    public function dismiss(Organization $organization, ?array $only = null, ?int $user_id = null): void
    {
        $snapshot = $this->snapshot($organization);
        $only ??= self::ASPECTS;
        $drifted = array_values(array_intersect($this->drift($organization, $this->current($organization, $snapshot)), $only));

        $this->record($organization, $snapshot, null, $only);

        if ($drifted !== []) {
            OrganizationUpdateState::where('organization_id', $organization->id)->whereIn('aspect', $drifted)->update([
                'dismissed_at' => now(),
                'dismissed_by_id' => $user_id,
            ]);
        }
    }

    /**
     * Notes that an update was started on its own for what is out of date.
     *
     * @param  array<int, string>  $aspects
     */
    public function markAttempt(Organization $organization, array $aspects): void
    {
        OrganizationUpdateState::where('organization_id', $organization->id)
            ->whereIn('aspect', $aspects)
            ->update(['apply_attempts' => DB::raw('apply_attempts + 1'), 'apply_attempted_at' => now()]);
    }

    /**
     * What the plan gives the organization that SubscriptionUpdate works out for
     * it: whether it can have domains and email, and how many.
     */
    private function capabilities(Organization $organization): string
    {
        $plan = $organization->plan;
        $settings = (array) $plan?->settings;

        return Fingerprint::of([
            'plan_id' => $plan?->id,
            'domain_enabled' => $plan?->domain_enabled,
            'domain_max' => $plan?->getAttribute('domain_max'),
            'email_enabled' => $plan?->email_enabled,
            'email_server_id' => $plan?->email_server_id,
            'domains' => Arr::get($settings, 'domains'),
            'email' => Arr::get($settings, 'email'),
        ]);
    }

    /**
     * An organization that was brought up to date before this existed has what it
     * was brought up to date for in its settings: that is where it stood, so it is
     * stored here and compared with, rather than taken as unknown.
     */
    private function importLegacy(Organization $organization): void
    {
        if ($this->states($organization)->has(self::BILLING)) {
            return;
        }

        if ($legacy = SubscriptionSnapshot::fromArray($organization->setting(self::LEGACY_SETTING))) {
            $this->record($organization, $legacy);
        }
    }
}
