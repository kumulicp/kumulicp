<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Domains\UpdateDnsRecords;
use App\Actions\Steps\Step;
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

        try {
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
        } catch (Throwable $e) {
            report($e);
            Log::warning(__('messages.web_move.dns_failed', ['app' => $app_instance->label]), ['organization_id' => $task->organization_id]);
        }

        if ($manual) {
            Log::warning(__('messages.web_move.dns_manual', [
                'app' => $app_instance->label,
                'domains' => implode(', ', $manual),
                'ip' => $ip,
            ]), ['organization_id' => $task->organization_id]);
        }

        return ActionResult::done(['dns_manual' => $manual]);
    }
}
