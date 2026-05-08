<?php

namespace Tests\Feature\Profile;

use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
    }

    public function test_unauthenticated_users_are_redirected_from_profile()
    {
        $response = $this->get('/profile');

        $response->assertRedirect('/login');
    }

    public function test_unauthenticated_post_to_profile_is_redirected()
    {
        $response = $this->post('/profile', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'personal_email' => 'jane@example.com',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_first_name_is_required()
    {
        $response = $this->actingAs($this->user)->post('/profile', [
            'first_name' => '',
            'last_name' => 'User',
            'personal_email' => 'demo@example.com',
        ]);

        $response->assertSessionHasErrors('first_name');
    }

    public function test_last_name_is_required()
    {
        $response = $this->actingAs($this->user)->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => '',
            'personal_email' => 'demo@example.com',
        ]);

        $response->assertSessionHasErrors('last_name');
    }

    public function test_personal_email_must_be_a_valid_email()
    {
        $response = $this->actingAs($this->user)->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => 'User',
            'personal_email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('personal_email');
    }

    public function test_personal_email_is_required()
    {
        $response = $this->actingAs($this->user)->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => 'User',
            'personal_email' => '',
        ]);

        $response->assertSessionHasErrors('personal_email');
    }

    public function test_first_name_cannot_exceed_100_characters()
    {
        $response = $this->actingAs($this->user)->post('/profile', [
            'first_name' => str_repeat('a', 101),
            'last_name' => 'User',
            'personal_email' => 'demo@example.com',
        ]);

        $response->assertSessionHasErrors('first_name');
    }

    public function test_last_name_cannot_exceed_100_characters()
    {
        $response = $this->actingAs($this->user)->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => str_repeat('a', 101),
            'personal_email' => 'demo@example.com',
        ]);

        $response->assertSessionHasErrors('last_name');
    }

    public function test_password_update_requires_current_password()
    {
        $response = $this->actingAs($this->user)->post('/profile/update/passwd', [
            'current_password' => '',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertSessionHasErrors('current_password');
    }

    public function test_password_update_rejects_wrong_current_password()
    {
        $response = $this->actingAs($this->user)->post('/profile/update/passwd', [
            'current_password' => 'wrongpassword',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertSessionHasErrors('current_password');
    }

    public function test_password_update_requires_confirmation_to_match()
    {
        $response = $this->actingAs($this->user)->post('/profile/update/passwd', [
            'current_password' => 'demouser',
            'password' => 'NewPass123!',
            'password_confirmation' => 'DifferentPass456!',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
