<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Domains\UpdateDnsRecords;
use App\Actions\Steps\Step;
use App\AppInstance;
use App\OrgServer;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The app's domains were pointed at the old server's address: the subdomains hold a copy of it, and
 * the records of a domain the control panel manages are made from the app's server. So the copies
 * are updated and the managed domains' records are pushed again. A domain that is not managed is
 * the customer's to change, and is said so in the organization's log.
 *
 * The same is done for the apps that run in its release (`child_ids`, saved when the move was asked
 * for): the apps shared from a shared app have domains of their own, in the organizations that use
 * them, and those organizations are the ones told about the domains that are not managed here.
 *
 * Not something to undo a move that works for: it can't fail the move, and says what it could not do.
 */
class RefreshDns extends Step
{
    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance->fresh();
        $previous_ip = OrgServer::find($task->getValue('previous_org_server_id'))?->server?->ip;
        $ip = $app_instance->web_server?->server?->ip;
        $manual = [];
        $children_manual = [];

        try {
            $manual = $this->refresh($app_instance, $previous_ip, $ip);
        } catch (Throwable $e) {
            $this->failed($app_instance, $e);
        }

        if ($manual) {
            Log::warning(__('messages.web_move.dns_manual', [
                'app' => $app_instance->label,
                'domains' => implode(', ', $manual),
                'ip' => $ip,
            ]), ['organization_id' => $task->organization_id]);
        }

        // One app's domains failing does not stop the others being refreshed
        foreach (AppInstance::whereIn('id', $task->getValue('child_ids') ?? [])->get() as $child) {
            try {
                $unmanaged = $this->refresh($child, $previous_ip, $ip);
            } catch (Throwable $e) {
                $this->failed($child, $e);

                continue;
            }

            if ($unmanaged) {
                $children_manual[$child->id] = $unmanaged;

                // To the organization the app is in, which is the one that has the domain to change
                Log::warning(__('messages.web_move.child_dns_manual', [
                    'app' => $child->label,
                    'parent' => $app_instance->label,
                    'domains' => implode(', ', $unmanaged),
                    'ip' => $ip,
                ]), ['organization_id' => $child->organization_id]);
            }
        }

        return ActionResult::done(['dns_manual' => $manual] + ($children_manual ? ['child_dns_manual' => $children_manual] : []));
    }

    /**
     * The app's subdomains that held the old address now hold the new one, and the managed domains
     * they are in are asked to publish it.
     *
     * @return array<int, string> The names of the domains that are not managed here
     */
    private function refresh(AppInstance $app_instance, ?string $previous_ip, ?string $ip): array
    {
        $manual = [];

        foreach ($app_instance->domains()->with('domain')->get() as $subdomain) {
            // Only a copy of the old address is the new one's to be
            if ($ip && $previous_ip && $subdomain->value === $previous_ip) {
                $subdomain->value = $ip;
                $subdomain->save();
            }
        }

        $domains = $app_instance->domains()->with('domain')->get()->pluck('domain')->filter()->unique('id');

        foreach ($domains as $domain) {
            if ($domain->type === 'managed') {
                ActionFacade::execute(new UpdateDnsRecords($app_instance->organization, $domain));
            } else {
                $manual[] = $domain->name;
            }
        }

        return $manual;
    }

    private function failed(AppInstance $app_instance, Throwable $e): void
    {
        report($e);
        Log::warning(__('messages.web_move.dns_failed', ['app' => $app_instance->label]), ['organization_id' => $app_instance->organization_id]);
    }
}
