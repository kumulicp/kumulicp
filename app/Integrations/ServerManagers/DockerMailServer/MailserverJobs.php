<?php

namespace App\Integrations\ServerManagers\DockerMailServer;

use App\Integrations\ServerManagers\DockerMailServer\Charts\MailserverJobChart;
use Illuminate\Support\Str;

class MailserverJobs extends MailserverJobChart
{
    public function addDkimKey()
    {
        $job_name = 'mailserver-job-'.Str::lower(Str::random(10));
        $return_url = env('APP_URL').'/api/dkim/'.$job_name.'?api_token='.$this->domain->organization->api_token;
        $this->run(
            command: [
                '/bin/sh', '-c',
            ],
            args: [
                '(supervisord -c /etc/supervisor/supervisord.conf &) && sleep 10 && setup config dkim domain '.$this->domain->name.' | grep DKIM1 > /dkim_key && curl -X POST '.$return_url.' -d dkim_public_key="$(cat /dkim_key)"',
            ],
            env: [
                [
                    'name' => 'ACCOUNT_PROVISIONER',
                    'value' => 'LDAP',
                ],
                [
                    'name' => 'AMAVIS_LOGLEVEL',
                    'value' => '0',
                ],
                [
                    'name' => 'DOVECOT_AUTH_BIND',
                    'value' => 'no',
                ],
                [
                    'name' => 'DOVECOT_INET_PROTOCOLS',
                    'value' => 'all',
                ],
                [
                    'name' => 'DOVECOT_MAILBOX_FORMAT',
                    'value' => 'maildir',
                ],
                [
                    'name' => 'DOVECOT_PASS_ATTRS',
                    'value' => 'mail=user,userPassword=password',
                ],
                [
                    'name' => 'DOVECOT_PASS_FILTER',
                    'value' => '(&(objectClass=inetOrgPerson)(|(cn=%n)(mail=%n@%d)))',
                ],
                [
                    'name' => 'DOVECOT_USER_ATTRS',
                    'value' => 'mailHomeDirectory=home,carLicense=uid,carLicense=gid,mailStorageDirectory=mail,businessCategory=quota_rule=*:storage=%$',
                ],
                [
                    'name' => 'DOVECOT_USER_FILTER',
                    'value' => '(&(objectClass=inetOrgPerson)(|(cn=%n)(mail=%n@%d)))',
                ],
                [
                    'name' => 'ENABLE_AMAVIS',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_CLAMAV',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_DNSBL',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_FAIL2BAN',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_FETCHMAIL',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_GETMAIL',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_IMAP',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_MANAGESIEVE',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_OPENDKIM',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_OPENDMARC',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_POLICYD_SPF',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_POSTGREY',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_QUOTAS',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_RSPAMD',
                    'value' => '1',
                ],
                [
                    'name' => 'ENABLE_RSPAMD_REDIS',
                    'value' => '1',
                ],
                [
                    'name' => 'ENABLE_SASLAUTHD',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_SPAMASSASSIN',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_SPAMASSASSIN_KAM',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_SRS',
                    'value' => '0',
                ],
                [
                    'name' => 'ENABLE_UPDATE_CHECK',
                    'value' => '1',
                ],
                [
                    'name' => 'FAIL2BAN_BLOCKTYPE',
                    'value' => 'drop',
                ],
                [
                    'name' => 'FETCHMAIL_PARALLEL',
                    'value' => '0',
                ],
                [
                    'name' => 'FETCHMAIL_POLL',
                    'value' => '300',
                ],
                [
                    'name' => 'GETMAIL_POLL',
                    'value' => '5',
                ],
                [
                    'name' => 'LOGROTATE_INTERVAL',
                    'value' => 'weekly',
                ],
                [
                    'name' => 'LOG_LEVEL',
                    'value' => 'info',
                ],
                [
                    'name' => 'MARK_SPAM_AS_READ',
                    'value' => '0',
                ],
                [
                    'name' => 'MOVE_SPAM_TO_JUNK',
                    'value' => '1',
                ],
                [
                    'name' => 'ONE_DIR',
                    'value' => '1',
                ],
                [
                    'name' => 'OVERRIDE_HOSTNAME',
                    'value' => '',
                ],
                [
                    'name' => 'PERMIT_DOCKER',
                    'value' => 'none',
                ],
                [
                    'name' => 'PFLOGSUMM_RECIPIENT',
                ],
                [
                    'name' => 'PFLOGSUMM_SENDER',
                ],
                [
                    'name' => 'PFLOGSUMM_TRIGGER',
                ],
                [
                    'name' => 'POSTFIX_DAGENT',
                ],
                [
                    'name' => 'POSTFIX_INET_PROTOCOLS',
                    'value' => 'all',
                ],
                [
                    'name' => 'POSTFIX_MAILBOX_SIZE_LIMIT',
                ],
                [
                    'name' => 'POSTFIX_MESSAGE_SIZE_LIMIT',
                ],
                [
                    'name' => 'POSTFIX_REJECT_UNKNOWN_CLIENT_HOSTNAME',
                    'value' => '0',
                ],
                [
                    'name' => 'POSTGREY_AUTO_WHITELIST_CLIENTS',
                    'value' => '5',
                ],
                [
                    'name' => 'POSTGREY_DELAY',
                    'value' => '300',
                ],
                [
                    'name' => 'POSTGREY_MAX_AGE',
                    'value' => '35',
                ],
                [
                    'name' => 'POSTGREY_TEXT',
                    'value' => 'Delayed by Postgrey',
                ],
                [
                    'name' => 'POSTSCREEN_ACTION',
                    'value' => 'enforce',
                ],
                [
                    'name' => 'RELAY_PASSWORD',
                ],
                [
                    'name' => 'RELAY_PORT',
                    'value' => '25',
                ],
                [
                    'name' => 'RSPAMD_CHECK_AUTHENTICATED',
                    'value' => '0',
                ],
                [
                    'name' => 'RSPAMD_GREYLISTING',
                    'value' => '0',
                ],
                [
                    'name' => 'RSPAMD_HFILTER',
                    'value' => '1',
                ],
                [
                    'name' => 'RSPAMD_HFILTER_HOSTNAME_UNKNOWN_SCORE',
                    'value' => '6',
                ],
                [
                    'name' => 'RSPAMD_LEARN',
                    'value' => '0',
                ],
                [
                    'name' => 'SA_KILL',
                    'value' => '10',
                ],
                [
                    'name' => 'SA_SPAM_SUBJECT',
                    'value' => '***SPAM*** ',
                ],
                [
                    'name' => 'SA_TAG',
                    'value' => '2',
                ],
                [
                    'name' => 'SA_TAG2',
                    'value' => '6.31',
                ],
                [
                    'name' => 'SMTP_ONLY',
                ],
                [
                    'name' => 'SPAMASSASSIN_SPAM_TO_INBOX',
                    'value' => '1',
                ],
                [
                    'name' => 'SPOOF_PROTECTION',
                    'value' => '1',
                ],
                [
                    'name' => 'SRS_SENDER_CLASSES',
                    'value' => 'envelope_sender',
                ],
            ],
            job_name: $job_name,
        );

        return $this;
    }
}
