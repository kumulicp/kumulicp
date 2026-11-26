<?php

namespace App\Actions\Email;

use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Mailbox;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Domain;
use App\Task;
use Illuminate\Support\Facades\Log;

/**
 * Takes a user's mailbox off the mail server once the control panel is done with it.
 *
 * Removing (or undoing) an organization address changes the user and deletes the panel's own record
 * first, and doesn't wait for the mail server: it may be down. What it couldn't delete is left to
 * this task, which tries again with a growing wait. When the attempts run out the task fails, which
 * is how a control panel admin gets to see it (the dashboard, the tasks page), and they can retry
 * it from there once the server is back.
 */
class DeleteMailserverMailbox extends NativeAction
{
    public $slug = 'delete_mailserver_mailbox';

    public $background = true;

    // About a day of trying: a mail server that is down for longer needs someone to look at it
    public static int $max_attempts = 12;

    public static array $backoff = [60, 300, 900, 1800, 3600, 7200];

    public function __construct(Organization $organization, OrgDomain $domain, string $address, string $username)
    {
        $this->organization = $organization;

        $this->setCustomValues([
            'domain_name' => $domain->name,
            'address' => strtolower($address),
            'username' => $username,
        ]);

        $this->description = __('actions.deleting_mailserver_mailbox', ['address' => $address]);
    }

    public static function handle(Task $task): ActionResult
    {
        $address = (string) $task->getValue('address');
        $username = (string) $task->getValue('username');

        // Given out again in the meantime: what is on the server is that mailbox now
        if (Mailbox::where('address', $address)->where('status', Mailbox::ACTIVE)->exists()) {
            return ActionResult::done();
        }

        $domain = $task->organization->domains()->where('name', $task->getValue('domain_name'))->first();
        $user = AccountManager::users($task->organization)->find($username);

        if (! $domain || ! $user) {
            Log::info('Nothing to delete from the mail server', ['task_id' => $task->id, 'address' => $address]);

            return ActionResult::done();
        }

        $server = Domain::connect($domain, 'email');

        // An error here is for the runner: it tries again later, then fails the task
        if (method_exists($server, 'deleteUserEmail')) {
            $server->deleteUserEmail($user, $address);
        }

        return ActionResult::done();
    }
}
