<?php

namespace Tests\Feature\Auth;

use App\Providers\RouteServiceProvider;
use App\ServerSetting;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $setting = new ServerSetting;
        $setting->key = 'installed';
        $setting->value = 1;
        $setting->save();

        User::find(1);
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $support = (new TestSupports)->seed();

        $response = $this->post('/login', [
            'email' => 'demo@example.com',
            'password' => 'demouser',
        ]);

        $response->assertRedirect(RouteServiceProvider::HOME);
        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $support = (new TestSupports)->seed();

        $this->post('/login', [
            'email' => 'demo@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }
}
