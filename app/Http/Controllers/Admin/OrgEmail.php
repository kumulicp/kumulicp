<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Email\RecoveryEmailService;
use App\Support\Email\LdapEmailAttributes;
use App\UserRecoveryEmail;

class OrgEmail extends Controller
{
    public function index()
    {
        return inertia()->render('Admin/Email/EmailSync', [
            'ldap_enabled' => LdapEmailAttributes::usingLdap(),
            'aliases' => [
                'enabled' => LdapEmailAttributes::aliasesEnabled(),
                'attribute' => LdapEmailAttributes::aliasAttribute(),
            ],
            'recovery' => [
                'attribute' => LdapEmailAttributes::recoveryAttribute(),
                'sync_enabled' => LdapEmailAttributes::recoverySyncEnabled(),
                'count' => UserRecoveryEmail::count(),
                'missing' => app(RecoveryEmailService::class)->countNeedingReminder(),
            ],
            'sync_result' => session('sync_result'),
            'breadcrumbs' => [
                ['label' => __('labels.email')],
            ],
        ]);
    }

    /**
     * Copies what the database holds over to the directory, for when the two have
     * drifted apart. Runs in the request: it only touches accounts with a recovery email.
     */
    public function sync(RecoveryEmailService $recovery_emails)
    {
        $result = $recovery_emails->sync();

        if (! $result['enabled']) {
            return redirect('/admin/email')->with('error', __('admin.email.sync_unavailable'));
        }

        $flash = count($result['failed']) > 0 || count($result['missing']) > 0 ? 'error' : 'success';

        return redirect('/admin/email')
            ->with($flash, __('admin.email.sync_finished', [
                'updated' => $result['updated'],
                'unchanged' => $result['unchanged'],
                'problems' => count($result['failed']) + count($result['missing']),
            ]))
            ->with('sync_result', $result);
    }
}
