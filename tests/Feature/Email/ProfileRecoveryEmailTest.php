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

        // The directory outlives the database rollback: clear what an earlier run left so the demo user is seeded as it should be
        (new TestSupports)->cleanLdap();
        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($this->user);
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);
        $this->service = $this->app->make(RecoveryEmailService::class);
    }

    protected function tearDown(): void
    {
        (new TestSupports)->cleanLdap();

        parent::tearDown();
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

    private function confirmUrl(string $address, string $username = 'demo'): string
    {
        return URL::temporarySignedRoute('profile.recovery.confirm', now()->addHour(), ['username' => $username, 'hash' => sha1($address)]);
    }

    public function test_the_link_in_the_email_carries_the_username()
    {
        Notification::fake();
        $this->service->request('demo', 'backup@gmail.com', $this->user->email);

        Notification::assertSentOnDemand(RecoveryEmailConfirmation::class, fn ($notification) => str_contains($notification->url, '/profile/recovery-email/demo/'.sha1('backup@gmail.com')));
    }

    public function test_opening_the_signed_link_confirms_the_address_without_needing_or_touching_the_session()
    {
        Notification::fake();
        $this->service->request('demo', 'backup@gmail.com', $this->user->email);

        // The session cookie is SameSite=strict, so a link opened from an email arrives without it
        $response = $this->get($this->confirmUrl('backup@gmail.com'));

        $response->assertOk();
        $response->assertSee('data-confirmed="true"', false);
        $response->assertSee(__('profile.recovery.confirmed'));
        $response->assertSee('href="'.url('/profile').'"', false);
        $response->assertCookieMissing(config('session.cookie'));
        $this->assertSame('backup@gmail.com', $this->service->get('demo'));
    }

    public function test_a_link_that_is_not_signed_is_refused()
    {
        $this->service->request('demo', 'backup@gmail.com', $this->user->email);

        $this->get('/profile/recovery-email/demo/'.sha1('backup@gmail.com'))->assertForbidden();

        $this->assertNotSame('backup@gmail.com', $this->service->get('demo'));
    }

    public function test_a_signed_link_cannot_be_pointed_at_someone_else()
    {
        $this->service->request('demo', 'backup@gmail.com', $this->user->email);

        // Changing the username breaks the signature
        $url = str_replace('/recovery-email/demo/', '/recovery-email/other/', $this->confirmUrl('backup@gmail.com'));

        $this->get($url)->assertForbidden();
        $this->assertNotSame('backup@gmail.com', $this->service->get('demo'));
    }

    public function test_a_link_for_an_address_that_was_not_asked_for_says_so()
    {
        $response = $this->get($this->confirmUrl('nobody@gmail.com'));

        $response->assertOk();
        $response->assertSee('data-confirmed="false"', false);
        $response->assertSee(__('profile.recovery.invalid'));
    }

    public function test_a_link_for_someone_who_does_not_exist_says_so()
    {
        $response = $this->get($this->confirmUrl('backup@gmail.com', 'nobody'));

        $response->assertOk();
        $response->assertSee('data-confirmed="false"', false);
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
