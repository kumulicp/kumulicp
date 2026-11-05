<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\Enums\TaskStatus;
use App\Jobs\Applications\UpdateLDAPGroups;
use App\Notifications\SubscriptionUpdatedNotification;
use App\Organization;
use App\Services\SubscriptionService;
use App\Support\SubscriptionSnapshot;
use App\Support\Facades\Billing;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Brings everything that depends on an organization's subscription up to date.
 *
 * Callers fire this for all sorts of reasons (a user was added, an app was
 * customized, a payment method changed...) and most of them change nothing the
 * subscription cares about, so the work is only done when it is needed. The
 * state of the subscription is compared to a snapshot taken the last time this
 * completed:
 *
 *  - The billing provider is updated when the pricing, plan selection, status,
 *    discount or trial of the organization or any of its apps changed. Pass
 *    $forceBilling for changes that don't show up in the snapshot, like a new
 *    or removed payment method.
 *  - App LDAP groups are updated when plan selection or plan settings (such as
 *    storage) changed, or an app was activated or deactivated.
 *  - Admins are only notified when the organization or one of its apps moved to
 *    a different plan, and the notification says which. Never for background
 *    updates, or while the organization is still being set up.
 */
class SubscriptionUpdate extends Action
{
    public const SNAPSHOT_SETTING = 'subscription_snapshot';

    public $slug = 'subscription_update';

    public $prerequisites;

    public $subscription;

    public function __construct(Organization $organization, SubscriptionService $subscription, bool $forceBilling = false)
    {
        $this->organization = $organization;
        $this->subscription = $subscription;
        $this->prerequisites = null;
        // run() rebuilds the subscription from the database, so nothing about it
        // is stored (an unserialize() of database contents breaks across deploys
        // and is an object-injection risk). The timestamp keeps successive
        // updates from being collapsed into one by Action::task_exists().
        $this->setCustomValues(['requested_at' => now()->toIso8601String(), 'force_billing' => $forceBilling]);
        $this->description = __('actions.updating_subscription');
    }

    public static function run(Task $task)
    {
        $organization = $task->organization;

        $subscription = (new SubscriptionService($organization))->refresh();

        FastCache::clear(organization: $task->organization);

        OrganizationFacade::setOrganization($task->organization);

        $previous = SubscriptionSnapshot::fromArray($organization->setting(self::SNAPSHOT_SETTING));
        $current = SubscriptionSnapshot::capture($subscription);

        $billing_changed = (bool) Arr::get($task->custom_values, 'force_billing') || $current->billingChanged($previous);
        $access_changed = $current->accessChanged($previous);

        if ($billing_changed) {
            Billing::update();
        }

        Bus::chain([
            function () use ($organization) {
                // Update Subscription Summary with correct info
                $organization->deactivate_at = null;
                $organization->save();
            },
        ])->catch(function (Throwable $e) use ($task) {
            $task->error_message = $e->getMessage();
            $task->status = TaskStatus::FAILED;
            $task->save();
        })->dispatch();

        if ($access_changed) {
            foreach ($task->organization->app_instances as $app) {
                UpdateLDAPGroups::dispatch($app);
            }
        }

        if (! $subscription->anyPending()) {
            $task->organization->updateSetting('domains_enabled', Gate::allows('view-domains'));
            $task->organization->updateSetting('emails_enabled', Gate::allows('view-emails'));
            // Only remembered once everything is settled, so a change that is still
            // pending gets compared (and notified about) by the next update
            $task->organization->updateSetting(self::SNAPSHOT_SETTING, $current->toArray());
            $task->organization->save();

            $task->complete();

            // When organization account first created, it should send other emails
            if ($task->organization->status !== 'new' && ! $task->background) {
                $plan_changes = $current->planChanges($previous);

                if (count($plan_changes) > 0) {
                    $task->organization->notifyAdmins(new SubscriptionUpdatedNotification($plan_changes));
                }
            }
        }

        FastCache::clear(organization: $task->organization);
    }

    public static function retry(Task $task)
    {
        return new self(
            $task->organization,
            new SubscriptionService($task->organization),
            (bool) Arr::get($task->custom_values, 'force_billing'),
        );
    }

    public static function complete(Task $task) {}
}
