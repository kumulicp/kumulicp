<?php

namespace App\Integrations\ServerManagers\DockerMailServer\Interfaces;

use App\Contracts\OrganizationInterface;
use App\Contracts\ServerManager\EmailContract;
use App\Integrations\ServerManagers\DockerMailServer\MailserverJobs;
use App\Integrations\ServerManagers\Rancher\Actions\RunJob;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Domain;
use App\Ldap\Models\Email;
use App\Ldap\Models\User;
use App\OrgDomain;
use App\Server;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use LdapRecord\Models\Attributes\Password;

class LdapEmailInterface implements EmailContract, OrganizationInterface
{
    public function existsOrganization()
    {
        return true;
    }

    public function organization()
    {
        return [
            'customerid' => 'ldap',
        ];
    }

    public function addOrganization()
    {
        return true;
    }

    public function updateOrganization()
    {
        return true;
    }

    public function deleteOrganization()
    {
        return true;
    }

    public function hasOrganizationError()
    {
        return false;
    }

    public function existsEmail(OrgDomain $domain, string $address)
    {
        return ! is_null(Email::in(Dn::create($domain->organization, 'emails'))->where('mail', $address)->first());
    }

    public function emailList(OrgDomain $domain)
    {
        $emails = Email::in(Dn::create($domain->organization, 'emails'))->whereContains('mail', $domain->name)->get();

        return $emails->map(function ($email) {
            return [
                'email' => $email->getFirstAttribute('mail'),
                'name' => $email->getFirstAttribute('displayName'),
                'username' => $email->getFirstAttribute('uid'),
            ];
        })->all();
    }

    public function email(OrgDomain $domain, string $username)
    {
        $address = $username.'@'.$domain->name;
        $email = Email::in(Dn::create($domain->organization, 'emails'))->where('mail', $address)->first();

        return [
            'email' => $email->getFirstAttribute('mail'),
            'name' => $email->getFirstAttribute('displayName'),
            'username' => $email->getFirstAttribute('uid'),
        ];
    }

    public function addEmail(OrgDomain $domain, string $name, string $username, string $password, ?string $type = null)
    {
        $organization = $domain->organization;
        $address = $username.'@'.$domain->name;
        $email = Email::in(Dn::create($organization, 'emails'))->where('mail', $address)->first();

        if (! $email) {
            $email_location = "/var/mail/{$organization->slug}/$address/";

            $email = new Email;
            $email->cn = $address;
            $email->mail = $address;
            $email->uid = $username;
            $email->sn = 'email';
            $email->displayName = $name;
            $email->mailQuota = $domain->organization->plan->setting('email.storage').'G';
            $email->mailHomeDirectory = $email_location;
            $email->mailStorageDirectory = 'maildir:'.$email_location;
            $email->setDn(Dn::create($organization, 'emails', $address));
            $email->userPassword = Password::md5Crypt($password);
            $email->save();
        }

        return [
            'email' => $email->getFirstAttribute('mail'),
            'name' => $email->getFirstAttribute('displayName'),
            'username' => $email->getFirstAttribute('uid'),
            'quota' => $email->getFirstAttribute('mailQuota'),
        ];
    }

    public function updateEmail(OrgDomain $domain, string $name, string $address, ?string $password = null, ?string $type = null)
    {
        $email = Email::in(Dn::create($domain->organization, 'emails'))->where('mail', $address)->first();

        if ($email) {
            $email->displayName = $name;
            if ($password) {
                $email->userPassword = Password::md5Crypt($password);
            }
            $email->mailQuota = $domain->organization->plan->setting('email.storage');
            $email->save();

            return [
                'email' => $email->getFirstAttribute('mail'),
                'name' => $email->getFirstAttribute('displayName'),
                'username' => $email->getFirstAttribute('uid'),
                'quota' => $email->getFirstAttribute('mailQuota'),
            ];
        }

        return [];
    }

    public function deleteEmail(OrgDomain $domain, string $email_address)
    {
        if ($email = Email::in(Dn::create($domain->organization, 'emails'))->where('mail', $email_address)->first()) {
            $email->delete();
        }
    }

    public function listEmailForwarders(OrgDomain $domain) {}

    public function emailForwarders(string $email_address) {}

    public function deleteEmailForwarders(string $forwarder_address, string $destination_address) {}

    public function createUserEmail(UserManager $user, OrgDomain $domain)
    {
        $username = $user->attribute('username');
        if ($user = User::find(Dn::create($user->account(), 'users', $username))) {
            $email_address = "$username@{$domain->name}";
            $email_location = "/var/mail/{$domain->organization->slug}/$username/";

            $user->addAttributeValue('objectClass', 'PostfixBookMailAccount');
            $user->addAttributeValue('orgMail', $email_address);
            $user->mailQuota = $domain->organization->plan->setting('email.storage').'G';
            $user->mailHomeDirectory = $email_location;
            $user->mailStorageDirectory = 'maildir:'.$email_location;
            $user->save();
        }

        return [
            'username' => $user->getFirstAttribute('cn'),
            'emails' => $user->getAttribute('orgmail'),
        ];
    }

    public function deleteUserEmail(UserManager $user, string $email)
    {
        $username = $user->attribute('username');
        if ($user = User::find(Dn::create($user->account(), 'users', $username))) {
            $user->removeAttributes(['orgMail' => $email]);
            $user->save();
        }
    }

    public function createDkimKey(OrgDomain $domain)
    {
        $org_server = Organization::server(Server::find($domain->email_server->server->setting('rancher_server')));

        $action = Action::execute(new RunJob($org_server->get(), new MailserverJobs($domain), 'add_dkim_key', $domain->email_server->server->setting('namespace')));

        return [
            'job_id' => $action->id,
        ];
    }

    public function dkimKey(OrgDomain $domain, string $job_id)
    {
        $job_id = (int) $job_id;

        return [];
    }

    public function existsDomain(OrgDomain $domain)
    {
        return ! is_null(Domain::in(Dn::create($domain->organization, 'domains'))->where('dc', $domain->name)->first());
    }

    public function domain(OrgDomain $domain)
    {
        return [
            'id' => 'ldap',
        ];
    }

    public function addDomain(OrgDomain $domain)
    {
        $ldap = new Domain;
        $ldap->dc = $domain->name;
        $ldap->mail = "empty@{$domain->name}";
        $ldap->setDn("dc={$domain->name},".Dn::create($domain->organization, 'domains'));
        $ldap->save();

        return [
            'id' => 'ldap',
        ];
    }

    public function updateDomain(OrgDomain $domain)
    {
        return '';
    }

    public function deleteDomain(OrgDomain $domain)
    {
        if ($ldap = Domain::in(Dn::create($domain->organization, 'domains')->where('dc', $domain->name)->first())) {
            $ldap->delete();
        }

        return true;
    }

    public function hasDomainError()
    {
        return false;
    }

    public function domainError()
    {
        return false;
    }
}
