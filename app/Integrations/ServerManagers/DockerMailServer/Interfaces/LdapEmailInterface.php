<?php

namespace App\Integrations\ServerManagers\DockerMailServer\Interfaces;

use App\Contracts\OrganizationInterface;
use App\Contracts\ServerManager\EmailContract;
use App\Integrations\ServerManagers\DockerMailServer\MailserverJobs;
use App\Integrations\ServerManagers\Kubernetes\Actions\RunJob;
use App\Ldap\Actions\Dn;
use App\Ldap\Actions\PasswordHash;
use App\Ldap\Models\Domain;
use App\Ldap\Models\Email;
use App\Ldap\Models\User;
use App\OrgDomain;
use App\Server;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;

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

    /**
     * A mailbox quota the way the directory stores it: gigabytes with a trailing G (5 becomes "5G").
     * Null when the plan sets none, so the attribute is left out rather than written empty.
     */
    public static function quota(string|int|float|null $gigabytes): ?string
    {
        if (! is_numeric($gigabytes) || (float) $gigabytes <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $gigabytes, 2, '.', ''), '0'), '.').'G';
    }

    /**
     * @phpstan-impure
     */
    public function existsEmail(OrgDomain $domain, string $address)
    {
        return ! is_null(Email::in(Dn::create($domain->organization, 'emails'))->where('mail', $address)->first());
    }

    public function emailList(OrgDomain $domain)
    {
        $emails = Email::in(Dn::create($domain->organization, 'emails'))->whereEndsWith('mail', '@'.$domain->name)->get();

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
            if ($quota = self::quota($domain->organization->plan->setting('email.storage'))) {
                $email->mailQuota = $quota;
            }
            $email->mailHomeDirectory = $email_location;
            $email->mailStorageDirectory = 'maildir:'.$email_location;
            $email->setDn(Dn::create($organization, 'emails', $address));
            $email->userPassword = PasswordHash::make($password);
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
                $email->userPassword = PasswordHash::make($password);
            }
            if ($quota = self::quota($domain->organization->plan->setting('email.storage'))) {
                $email->mailQuota = $quota;
            }
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

    /**
     * Forwarding is not built on the directory: the methods below only answer "none" so a
     * caller that asks anyway doesn't get nothing back. Callers should ask this first.
     */
    public function supportsForwarders(): bool
    {
        return false;
    }

    public function listEmailForwarders(OrgDomain $domain)
    {
        return [];
    }

    public function emailForwarders(string $email_address)
    {
        return ['list' => []];
    }

    public function deleteEmailForwarders(string $forwarder_address, string $destination_address) {}

    public function createUserEmail(UserManager $user, OrgDomain $domain)
    {
        $username = $user->attribute('username');
        $ldap_user = User::find(Dn::create($user->organization(), 'users', $username));

        if (! $ldap_user instanceof User) {
            throw new \RuntimeException("User $username was not found in the directory.");
        }

        $email_address = strtolower("$username@{$domain->name}");
        $email_location = "/var/mail/{$domain->organization->slug}/$username/";

        // addAttributeValue() skips a value the user already has
        $ldap_user->addAttributeValue('objectClass', 'PostfixBookMailAccount');
        $ldap_user->addAttributeValue('orgMail', $email_address);
        if ($quota = self::quota($domain->organization->plan->setting('email.storage'))) {
            $ldap_user->mailQuota = $quota;
        }
        $ldap_user->mailHomeDirectory = $email_location;
        $ldap_user->mailStorageDirectory = 'maildir:'.$email_location;
        $ldap_user->save();

        return [
            'username' => $ldap_user->getFirstAttribute('cn'),
            'emails' => $ldap_user->getAttribute('orgmail'),
        ];
    }

    /**
     * Takes one address off the user. Once the user has none left, the mail attributes and
     * the mail object class that came with the first one go too, so a plain user is left.
     */
    public function deleteUserEmail(UserManager $user, string $email)
    {
        $username = $user->attribute('username');
        $ldap_user = User::find(Dn::create($user->organization(), 'users', $username));

        if (! $ldap_user instanceof User) {
            return;
        }

        $remaining = array_values(array_filter(
            $ldap_user->getAttribute('orgmail') ?? [],
            fn ($address) => strcasecmp($address, $email) !== 0,
        ));
        $ldap_user->setAttribute('orgMail', $remaining);

        if ($remaining === []) {
            foreach (['mailQuota', 'mailHomeDirectory', 'mailStorageDirectory'] as $attribute) {
                $ldap_user->setAttribute($attribute, []);
            }

            $ldap_user->setAttribute('objectClass', array_values(array_filter(
                $ldap_user->getAttribute('objectclass') ?? [],
                fn ($class) => strcasecmp($class, 'PostfixBookMailAccount') !== 0,
            )));
        }

        $ldap_user->save();
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
        if ($ldap = Domain::in(Dn::create($domain->organization, 'domains'))->where('dc', $domain->name)->first()) {
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

    public function hasEmailError()
    {
        return false;
    }

    public function emailError()
    {
        return false;
    }
}
