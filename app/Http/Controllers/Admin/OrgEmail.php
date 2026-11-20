<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\MailboxAddress;
use App\Services\Email\AliasDirectory;
use App\Services\Email\MailboxAliasService;
use App\Services\Email\RecoveryEmailService;
use App\Server;
use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\ServerInterface;
use App\UserRecoveryEmail;

class OrgEmail extends Controller
{
    public function index()
    {
        // A mail server that keeps aliases itself needs no LDAP attribute for them
        $server_managed = Server::where('type', 'email')->get()->contains(fn (Server $server) => ServerInterface::supportsAliases($server));

        return inertia()->render('Admin/Email/EmailSync', [
            'ldap_enabled' => LdapEmailAttributes::usingLdap(),
            'aliases' => [
                'enabled' => LdapEmailAttributes::aliasesEnabled() || $server_managed,
                'server_managed' => $server_managed,
                'attribute' => LdapEmailAttributes::aliasAttribute(),
                'sync_enabled' => app(AliasDirectory::class)->enabled(),
                'count' => MailboxAddress::where('is_primary', false)->count(),
            ],
            'recovery' => [
                'attribute' => LdapEmailAttributes::recoveryAttribute(),
                'sync_enabled' => LdapEmailAttributes::recoverySyncEnabled(),
                'count' => UserRecoveryEmail::count(),
                'missing' => app(RecoveryEmailService::class)->countNeedingReminder(),
            ],
            'sync_result' => session('sync_result'),
            'alias_sync_result' => session('alias_sync_result'),
            'breadcrumbs' => [
                ['label' => __('labels.email')],
            ],
        ]);
    }

    /**
     * Copies what the database holds over to the directory, for when the two have
     * drifted apart. Runs in the request: it only touches accounts with a recovery email
     * and mailboxes with aliases.
     */
    public function sync(RecoveryEmailService $recovery_emails, MailboxAliasService $aliases)
    {
        $recovery = $recovery_emails->sync();
        $alias = $aliases->sync();

        if (! $recovery['enabled'] && ! $alias['enabled']) {
            return redirect('/admin/email')->with('error', __('admin.email.sync_unavailable'));
        }

        $results = array_filter([$recovery, $alias], fn (array $result) => $result['enabled']);
        $problems = array_sum(array_map(fn (array $result) => count($result['failed']) + count($result['missing']), $results));

        return redirect('/admin/email')
            ->with($problems > 0 ? 'error' : 'success', __('admin.email.sync_finished', [
                'updated' => array_sum(array_column($results, 'updated')),
                'unchanged' => array_sum(array_column($results, 'unchanged')),
                'problems' => $problems,
            ]))
            ->with('sync_result', $recovery['enabled'] ? $recovery : null)
            ->with('alias_sync_result', $alias['enabled'] ? $alias : null);
    }
}
