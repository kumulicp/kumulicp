<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\Jobs\Applications\UpdateLDAPGroups;
use App\Jobs\Users\UpdateUserStorage;
use App\Notifications\SubscriptionUpdatedNotification;
use App\Organization;
use App\Services\SubscriptionService;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use App\Task;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Throwable;

class SubscriptionUpdate extends Action
{
    public $slug = 'subscription_update';

    public $prerequisites;

    public $subscription;

    public function __construct(Organization $organization, SubscriptionService $subscription)
    {
        $this->organization = $organization;
        $this->subscription = $subscription;
        $this->prerequisites = null;
        $this->setCustomValues(['subscription' => serialize($subscription)]);
        $this->description = __('actions.updating_subscription');
    }

    public static function run(Task $task)
    {
        $organization = $task->organization;

        $subscription = unserialize($task->getValue('subscription'));
        $subscription->refresh();

        OrganizationFacade::setOrganization($task->organization);
        Billing::update();
        Bus::chain([
            new UpdateUserStorage($organization),
            function () use ($organization) {
                // Update Subscription Summary with correct info
                $organization->deactivate_at = null;
                $organization->save();
            },
        ])->catch(function (Throwable $e) use ($task) {
            $task->error_message = $e->getMessage();
            $task->status = 'failed';
            $task->save();
        })->dispatch();

        foreach ($task->organization->app_instances as $app) {
            UpdateLDAPGroups::dispatch($app);
        }

        if (! $subscription->anyPending()) {
            $task->organization->updateSetting('domains_enabled', Gate::allows('view-domains'));
            $task->organization->updateSetting('emails_enabled', Gate::allows('view-emails'));
            $task->organization->save();

            $task->complete();

            // When organization account first created, it should send other emails
            if ($task->organization->status !== 'new' && ! $task->background) {
                $task->organization->notifyAdmins(new SubscriptionUpdatedNotification($task));
            }
        }
    }

    public static function retry(Task $task)
    {
        return new self($task->organization, unserialize($task->getValue('subscription')));
    }

    public static function complete(Task $task) {}
}
