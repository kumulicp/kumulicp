<?php

namespace App\Console\Commands;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\Admin;
use App\Support\Facades\AccountManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionLdapDirectoryReader extends Command
{
    protected $signature = 'ldap:provision-directory-reader {--rotate : Generate a new password even if one is already configured}';

    protected $description = 'Create or rotate the read-only cross-org LDAP service account used by shared app hubs (e.g. shared Nextcloud) -- never the LDAP rootDN.';

    public function handle(): int
    {
        AccountManager::initiate();

        $dn = 'cn=directory-reader,'.Dn::create('server');
        $account = Admin::find($dn);

        $configured_password = config('ldap.connections.directory_reader.password');

        if ($account && $configured_password && ! $this->option('rotate')) {
            $this->info('Directory reader account already exists and LDAP_DIRECTORY_READER_PASSWORD is already set -- nothing to do. Pass --rotate to generate a new password.');

            return self::SUCCESS;
        }

        $password = Str::password(20, true, true, false, false);

        if (! $account) {
            $account = new Admin;
            $account->cn = 'directory-reader';
            $account->setDn($dn);
        }

        $account->userPassword = '{CRYPT}'.crypt($password, '$1$'.Str::random(8).'$');
        $account->save();

        $this->info('Directory reader account provisioned at: '.$dn);
        $this->warn('Password (shown once -- store it now): '.$password);
        $this->line('Set this in .env / the deployment secret as LDAP_DIRECTORY_READER_PASSWORD.');

        return self::SUCCESS;
    }
}
