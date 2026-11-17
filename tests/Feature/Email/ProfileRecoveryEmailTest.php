<?php

namespace Tests\Feature\Email;

use App\Mailbox;
use App\Notifications\RecoveryEmailConfirmation;
use App\OrgDomain;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailService;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ProfileRecoveryEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private RecoveryEmailService $service;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($this->user);
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);
        $this->service = $this->app->make(RecoveryEmailService::class);
    }

    private function giveMailbox(): void
    {
        Mailbox::create([
            'organization_id' => $this->user->organization->id,
            'local_part' => 'demo',
            'address' => $this->user->email,
            'username' => 'demo',
            'status' => Mailbox::ACTIVE,
        ]);
    }

    public function test_the_profile_page_shows_the_recovery_email_and_whether_it_works()
    {
        $this->service->set('demo', 'backup@gmail.com');

        $this->get('/profile')->assertInertia(fn ($page) => $page
            ->component('Organization/Profile/ProfileEdit')
            ->where('recovery.email', 'backup@gmail.com')
            ->where('recovery.usable', true)
            ->where('recovery.pending', null)
            ->where('profile.has_mailbox', false));
    }

    public function test_asking_for_a_recovery_email_sends_a_confirmation_and_needs_the_password()
    {
        Notification::fake();

        $response = $this->post('/profile/recovery-email', [
            'recovery_email' => 'backup@gmail.com',
            'current_password' => 'demouser',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHasNoErrors();
        Notification::assertSentOnDemand(RecoveryEmailConfirmation::class);
        $this->assertSame('backup@gmail.com', $this->service->pending('demo'));
    }

    public function test_a_wrong_password_changes_nothing()
    {
        Notification::fake();

        $response = $this->post('/profile/recovery-email', [
            'recovery_email' => 'backup@gmail.com',
            'current_password' => 'not-the-password',
        ]);

        $response->assertSessionHasErrors('current_password');
        Notification::assertNothingSent();
        $this->assertNull($this->service->pending('demo'));
    }

    public function test_the_recovery_email_cannot_be_the_email_the_account_signs_in_with()
    {
        $response = $this->post('/profile/recovery-email', [
            'recovery_email' => strtoupper($this->user->email),
            'current_password' => 'demouser',
        ]);

        $response->assertSessionHasErrors(['recovery_email' => __('messages.rule.recovery_email_same')]);
    }

    public function test_the_recovery_email_cannot_be_on_a_hosted_domain()
    {
        OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'hosted.org',
            'email_status' => 'active',
        ]);

        $response = $this->post('/profile/recovery-email', [
            'recovery_email' => 'me@hosted.org',
            'current_password' => 'demouser',
        ]);

        $response->assertSessionHasErrors(['recovery_email' => __('messages.rule.recovery_email_hosted')]);
    }

    public function test_the_recovery_email_must_be_an_email()
    {
        $this->post('/profile/recovery-email', ['recovery_email' => 'nope', 'current_password' => 'demouser'])
            ->assertSessionHasErrors('recovery_email');
    }

    public function test_opening_the_signed_link_confirms_the_address()
    {
        Notification::fake();
        $this->service->request('demo', 'backup@gmail.com', $this->user->email);
        $url = URL::temporarySignedRoute('profile.recovery.confirm', now()->addHour(), ['hash' => sha1('backup@gmail.com')]);

        $response = $this->get($url);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');
        $this->assertSame('backup@gmail.com', $this->service->get('demo'));
    }

    public function test_a_link_that_is_not_signed_is_refused()
    {
        $this->service->request('demo', 'backup@gmail.com', $this->user->email);

        $this->get('/profile/recovery-email/'.sha1('backup@gmail.com'))->assertForbidden();

        $this->assertNotSame('backup@gmail.com', $this->service->get('demo'));
    }

    public function test_a_link_for_an_address_that_was_not_asked_for_says_so()
    {
        $url = URL::temporarySignedRoute('profile.recovery.confirm', now()->addHour(), ['hash' => sha1('nobody@gmail.com')]);

        $this->get($url)->assertRedirect('/profile')->assertSessionHas('error');
    }

    public function test_someone_with_an_organization_address_cannot_change_it_from_the_profile()
    {
        $this->giveMailbox();
        $before = $this->user->email;

        $response = $this->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => 'User',
            'personal_email' => 'sneaky@gmail.com',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($before, $this->user->fresh()->email);
    }

    public function test_someone_without_an_organization_address_still_edits_their_email_there()
    {
        $this->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => 'User',
            'personal_email' => 'changed@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('changed@example.com', $this->user->fresh()->email);
    }

    public function test_the_reminder_is_shown_to_someone_with_an_organization_address_and_no_recovery_email()
    {
        $this->get('/profile')->assertInertia(fn ($page) => $page->where('recovery_reminder', false));

        $this->giveMailbox();

        $this->get('/profile')->assertInertia(fn ($page) => $page->where('recovery_reminder', true));

        $this->service->set('demo', 'backup@gmail.com');

        $this->get('/profile')->assertInertia(fn ($page) => $page->where('recovery_reminder', false));
    }
}
