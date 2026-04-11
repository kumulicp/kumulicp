<?php

namespace Tests\Feature\Email;

use App\Actions\Email\AddEmailDomain;
use App\OrgDomain;
use App\Server;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Support\Facades\Subscription;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TestSupports;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_enable_email_domain()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();

        $this->withoutExceptionHandling();
        $rancher_server = Server::where('name', 'Rancher')->first();

        $email_server = Server::factory()->create([
            'name' => 'Ldap',
            'address' => 'localhost',
            'host' => 'localhost',
            'api_key' => 'localhost',
            'api_secret' => 'localhost',
            'default_web_server' => 'localhost',
            'internal_address' => 'localhost',
            'type' => 'email',
            'interface' => 'ldap',
            'settings' => '{"rancher_server": "'.$rancher_server->id.'","namespace": "default"}',
            'ip' => '127.0.0.1',
            'status' => 'active',
        ]);

        $support->base_1->domain_enabled = true;
        $support->base_1->email_enabled = true;
        $support->base_1->email_server()->associate($email_server);
        $support->base_1->save();

        $user = User::find(1);
        $this->actingAs($user);
        $this->followingRedirects();
        $support->setSubscription($user->organization, $support->base_1);
        Subscription::refresh();

        $this->assertFalse(Gate::allows('view-emails'));
        $domain = OrgDomain::where('name', 'k-nextcloud-1')->first();

        $add_email_domain = Action::execute(new AddEmailDomain($user->organization, $domain));

        Artisan::call('schedule:run');
        $add_email_domain->refresh();
        $this->assertTrue($add_email_domain->status == 'in_progress');

        $retrieve_dkim_key = Task::where('action_slug', 'retrieve_dkim_key')->first();
        $this->assertNotNull($retrieve_dkim_key);

        Artisan::call('schedule:run');

        $run_rancher_job = Task::where('action_slug', 'run_rancher_job')->first();
        $this->assertNotNull($run_rancher_job);

        Artisan::call('schedule:run');

        $run_rancher_job->refresh();
        // Assume rancher job completed successfull, this isn't testing whether the job works or not
        if ($run_rancher_job->status == 'in_progress') {
            $run_rancher_job->status = 'complete';
            $run_rancher_job->save();

            // When the rancher job is complete, it should successfully add the dkim key to the domain
            $domain->dkim_public_key = 'fake_public_key';
            $domain->save();
        }
        Artisan::call('schedule:run');
        $retrieve_dkim_key->refresh();
        $this->assertTrue($retrieve_dkim_key->status == 'complete');

        Artisan::call('schedule:run');
        $add_email_domain->refresh();
        $this->assertTrue($add_email_domain->status == 'complete');

        $this->assertTrue(Gate::allows('view-emails'));
    }

    public function test_add_email_accounts()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();

        $this->withoutExceptionHandling();
        $rancher_server = Server::where('name', 'Rancher')->first();

        $email_server = Server::factory()->create([
            'name' => 'Ldap',
            'address' => 'localhost',
            'host' => 'localhost',
            'api_key' => 'localhost',
            'api_secret' => 'localhost',
            'default_web_server' => 'localhost',
            'internal_address' => 'localhost',
            'type' => 'email',
            'interface' => 'ldap',
            'settings' => '{"rancher_server": "'.$rancher_server->id.'"}',
            'ip' => '127.0.0.1',
            'status' => 'active',
        ]);

        $support->base_1->email_enabled = true;
        $support->base_1->email_server()->associate($email_server);
        $support->base_1->domain_enabled = true;
        $support->base_1->save();

        $user = User::find(1);
        $this->actingAs($user);
        $this->followingRedirects();
        $support->setSubscription($user->organization, $support->base_1);
        $domain = OrgDomain::where('name', 'k-nextcloud-1')->first();
        $domain->email_enabled = true;
        $domain->email_status = 'active';
        $domain->save();

        $email_server = Domain::emailServer($domain);
        $add_email = $this->post('/settings/email/accounts', [
            'name' => 'Demo Email',
            'email' => 'demoemail',
            'domain' => $domain->id, // TODO: confirm domain is the organization's domain and is email enabled,
            'password' => 'demoemail',
            'password_confirmation' => 'demoemail',
        ]);

        $add_email->assertSee('demoemail@k-nextcloud-1');
        $add_email->assertSee('Demo Email');

        $this->followingRedirects();
        $update_email = $this->put('/settings/email/accounts/demoemail@k-nextcloud-1', [
            'name' => 'Demo Email2',
            'password' => 'demoemail1',
            'password_confirmation' => 'demoemail1',
        ]);

        $update_email->assertSee('Demo Email2');
        $this->followingRedirects();
        $delete_email = $this->delete('/settings/email/accounts/demoemail@k-nextcloud-1');

        $delete_email->assertDontSee('Demo Email2');
    }
}
