<?php

namespace Tests\Feature;

use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();

        $this->actingAs(User::find(1));
    }

    public function test_store_validation(): void
    {
        $this->post('/users', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'personal_email' => 'testuser@example.com',
        ])->assertInvalid(['username']);

        $this->post('/users', [
            'username' => 'TestUser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'personal_email' => 'testuser@example.com',
        ])->assertInvalid(['username']);

        $this->post('/users', [
            'username' => 'test-user!',
            'first_name' => 'Test',
            'last_name' => 'User',
            'personal_email' => 'testuser@example.com',
        ])->assertInvalid(['username']);

        $this->post('/users', [
            'username' => 'demo',
            'first_name' => 'Test',
            'last_name' => 'User',
            'personal_email' => 'testuser@example.com',
        ])->assertInvalid(['username']);

        $this->post('/users', [
            'username' => 'testuser',
            'last_name' => 'User',
            'personal_email' => 'testuser@example.com',
        ])->assertInvalid(['first_name']);

        $this->post('/users', [
            'username' => 'testuser',
            'first_name' => 'Test',
            'personal_email' => 'testuser@example.com',
        ])->assertInvalid(['last_name']);

        $this->post('/users', [
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
        ])->assertInvalid(['personal_email']);

        $this->post('/users', [
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'personal_email' => 'not-an-email',
        ])->assertInvalid(['personal_email']);

        $this->post('/users', [
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'personal_email' => 'demo@example.com',
        ])->assertInvalid(['personal_email']);
    }

    public function test_update_validation(): void
    {
        AccountManager::users()->add([
            'username' => 'otherusr',
            'first_name' => 'Other',
            'last_name' => 'User',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'password',
        ]);

        try {
            $this->put('/users/demo', [
                'first_name' => '',
                'last_name' => 'User',
                'personal_email' => 'demo@example.com',
            ])->assertInvalid(['first_name']);

            $this->put('/users/demo', [
                'first_name' => 'Demo',
                'last_name' => '',
                'personal_email' => 'demo@example.com',
            ])->assertInvalid(['last_name']);

            $this->put('/users/demo', [
                'first_name' => 'Demo',
                'last_name' => 'User',
                'personal_email' => 'not-an-email',
            ])->assertInvalid(['personal_email']);

            $this->put('/users/demo', [
                'first_name' => 'Demo',
                'last_name' => 'User',
                'personal_email' => 'other@example.com',
            ])->assertInvalid(['personal_email']);
        } finally {
            AccountManager::users()->find('otherusr')?->delete();
        }
    }
}
