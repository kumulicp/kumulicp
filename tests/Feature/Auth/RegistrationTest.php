<?php

namespace Tests\Feature\Auth;

use App\Actions\Organizations\DeleteOrganization;
use App\Organization;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestSupports;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $support = (new TestSupports)->seed();

        $this->withoutExceptionHandling();

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_register_new_organization_account()
    {
        $support = (new TestSupports)->seed();

        $this->withoutExceptionHandling();

        try {
            $response = $this->post('/register', [
                'username' => 'test2',
                'contact_email' => 'test2@example.com',
                'password' => 'Test1password!',
                'password_confirmation' => 'Test1password!',
                'contact_first_name' => 'test1',
                'contact_last_name' => 'user',
                'contact_phone_number' => '1234567890',
                'subdomain' => 'testing',
                'type' => 'business',
                'name' => 'test1',
                'description' => 'test1',
                'email' => 'test1@example.com',
                'phone_number' => '1234567890',
                'street' => 'test st',
                'zipcode' => 'zipcode',
                'city' => 'test city',
                'state' => 'AL',
                'country' => 'US',
                'type' => 'nonprofit',
                'terms_of_use' => true,
            ]);
        }
        // Catch this to attempt to destroy account anyway so it doesn't cause problems on future attempts
        catch (\Throwable $e) {
            if ($organization = Organization::where('slug', 'testing')->first()) {
                $organization = AccountManager::account($organization)->destroy();
            }

            throw new \Exception($e->getMessage().$e->getTraceAsString());
        }

        $this->assertAuthenticated();

        $organization = Organization::where('slug', 'testing')->first();
        $this->assertInstanceOf(Organization::class, $organization);
        $response->assertRedirect('/registered');
        $organization = AccountManager::account($organization)->destroy();
    }

    public function test_delete_organization_account()
    {
        $support = (new TestSupports)->seed();

        $organization = Organization::where('slug', 'demo')->first();
        $delete = Action::execute(new DeleteOrganization($organization));
        $delete->status = 'in_progress';
        $delete->save();

        Action::run($delete);

        $complete = false;
        while ($complete == false) {
            Artisan::call('schedule:run');
            // Action::complete($delete);
            $delete = Task::find($delete->id);
            $complete = is_null($delete);
            sleep(2);
        }
        $organization = Organization::where('slug', 'demo')->first();
        $this->assertNull($organization);
    }
}
