<?php

use App\Application;
use App\User;

describe('Admin Application Edit', function () {
    beforeEach(function () {
        $this->application = Application::factory()->create([
            'slug' => 'browser_test_app',
            'name' => 'Browser Test App',
            'category' => 'Original Category',
            'access_type' => 'basic',
            'short_description' => 'Original short description',
            'description' => 'Original description',
            'domain_option' => 'none',
        ]);

        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('shows the edit page with pre-filled form fields and updates the application', function () {
        visit("/admin/apps/{$this->application->slug}/edit")
            ->assertSee('Browser Test App')
            ->assertValue('#name input', 'Browser Test App')
            ->assertValue('#category input', 'Original Category')
            ->fill('#name input', 'Renamed App')
            ->fill('#category input', 'Updated Category')
            ->click('#submit')
            ->assertPathIs("/admin/apps/{$this->application->slug}/edit")
            ->assertValue('#name input', 'Renamed App')
            ->assertValue('#category input', 'Updated Category');

        expect($this->application->refresh()->name)->toBe('Renamed App');
        expect($this->application->category)->toBe('Updated Category');
    });
});
