<?php

use App\AppScreenshot;
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

describe('Admin Application Screenshots', function () {
    beforeEach(function () {
        $this->application = Application::factory()->create([
            'slug' => 'browser_test_app_screenshots',
            'name' => 'Browser Test App Screenshots',
            'access_type' => 'basic',
            'domain_option' => 'none',
        ]);

        $this->screenshotOne = AppScreenshot::create([
            'application_id' => $this->application->id,
            'filename' => 'browser-test-shot-one.png',
            'display_order' => 1,
        ]);
        $this->screenshotTwo = AppScreenshot::create([
            'application_id' => $this->application->id,
            'filename' => 'browser-test-shot-two.png',
            'display_order' => 2,
        ]);

        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        foreach ([$this->screenshotOne, $this->screenshotTwo] as $screenshot) {
            $path = storage_path('app/images/screenshots/'.$screenshot->filename);
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $pixel);
        }

        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    afterEach(function () {
        foreach ([$this->screenshotOne, $this->screenshotTwo] as $screenshot) {
            @unlink(storage_path('app/images/screenshots/'.$screenshot->filename));
        }
    });

    it('shows uploaded screenshots and opens a fullscreen carousel when a thumbnail is clicked', function () {
        $page = visit("/admin/apps/{$this->application->slug}/edit");

        $page->assertVisible('[data-testid="screenshot-thumb-'.$this->screenshotOne->id.'"]')
            ->assertVisible('[data-testid="screenshot-thumb-'.$this->screenshotTwo->id.'"]')
            ->click('[data-testid="screenshot-thumb-'.$this->screenshotOne->id.'"]')
            ->assertVisible('.screenshot-gallery__modal')
            ->assertVisible('.va-carousel__arrow--left')
            ->assertVisible('.va-carousel__arrow--right')
            ->click('.screenshot-gallery__modal-close')
            ->assertNotVisible('.screenshot-gallery__modal');
    });

    it('removes a screenshot and persists the removal after saving', function () {
        visit("/admin/apps/{$this->application->slug}/edit")
            ->click('[data-testid="screenshot-remove-'.$this->screenshotOne->id.'"]')
            ->assertNotVisible('[data-testid="screenshot-thumb-'.$this->screenshotOne->id.'"]')
            ->click('#submit')
            ->assertPathIs("/admin/apps/{$this->application->slug}/edit")
            ->assertNotVisible('[data-testid="screenshot-thumb-'.$this->screenshotOne->id.'"]')
            ->assertVisible('[data-testid="screenshot-thumb-'.$this->screenshotTwo->id.'"]');

        expect(AppScreenshot::find($this->screenshotOne->id))->toBeNull();
        expect(AppScreenshot::find($this->screenshotTwo->id))->not->toBeNull();
    });
});
