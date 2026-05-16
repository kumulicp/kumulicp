<?php

use App\Announcement;
use App\Organization;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Auth: non-admin users are forbidden

describe('non-admin user', function () {
    beforeEach(function () {
        (new TestSupports)->seed();
        $userInterface = AccountManager::users(Organization::find(1))->add([
            'username' => 'testnonAdmin',
            'first_name' => 'Test',
            'last_name' => 'User',
            'name' => 'Test User',
            'email' => 'testnonadmin@example.com',
            'password' => 'password',
            'phone_number' => '1234567890',
        ]);
        $userInterface->permissions()->addControlPanelAccess();
        $user = $userInterface->databaseUser();
        $user->email_verified_at = now();
        $user->save();
        $this->nonAdmin = $user;
    });

    it('cannot access admin announcements index', function () {
        $response = $this->actingAs($this->nonAdmin)->get('/admin/service/announcements');
        $response->assertForbidden();
    });

    it('cannot create announcement', function () {
        $response = $this->actingAs($this->nonAdmin)->post('/admin/service/announcements', [
            'title' => 'Test Announcement',
        ]);
        $response->assertForbidden();
    });

    it('cannot update announcement', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($this->nonAdmin)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Updated Title',
            'short_description' => 'Summary',
            'description' => '<p>Content</p>',
        ]);

        $response->assertForbidden();
    });

    it('cannot delete announcement', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($this->nonAdmin)->delete('/admin/service/announcements/'.$announcement->id);

        $response->assertForbidden();
    });
});

// Validation and CRUD: admin user

describe('admin user', function () {
    beforeEach(function () {
        (new TestSupports)->seed();
        $this->admin = User::where('username', 'demo')->firstOrFail();
    });

    // Validation: store

    it('store requires title', function () {
        $response = $this->actingAs($this->admin)->post('/admin/service/announcements', ['title' => '']);
        $response->assertSessionHasErrors('title');
    });

    it('store title cannot exceed 255 characters', function () {
        $response = $this->actingAs($this->admin)->post('/admin/service/announcements', [
            'title' => str_repeat('a', 256),
        ]);
        $response->assertSessionHasErrors('title');
    });

    // Validation: update

    it('update requires title', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($this->admin)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => '',
            'short_description' => 'Summary',
            'description' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('title');
    });

    it('update requires short description', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($this->admin)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Valid Title',
            'short_description' => '',
            'description' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('short_description');
    });

    it('update requires description', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($this->admin)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Valid Title',
            'short_description' => 'Summary',
            'description' => '',
        ]);

        $response->assertSessionHasErrors('description');
    });

    it('update title cannot exceed 255 characters', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($this->admin)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => str_repeat('a', 256),
            'short_description' => 'Summary',
            'description' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('title');
    });

    // CRUD: successful operations

    it('can create announcement', function () {
        $response = $this->actingAs($this->admin)->post('/admin/service/announcements', [
            'title' => 'New Announcement',
        ]);

        $response->assertRedirectContains('/admin/service/announcements/');
        $response->assertRedirectContains('/edit');
        $this->assertDatabaseHas('announcements', ['title' => 'New Announcement']);
    });

    it('can update announcement', function () {
        $announcement = Announcement::factory()->create(['title' => 'Old Title']);

        $response = $this->actingAs($this->admin)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Updated Title',
            'short_description' => 'A brief summary',
            'description' => '<p>Full content here</p>',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'Updated Title',
            'short_description' => 'A brief summary',
        ]);
    });

    it('can delete announcement', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($this->admin)->delete('/admin/service/announcements/'.$announcement->id);

        $response->assertRedirect('/admin/service/announcements');
        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    });
});

// User announcement view

it('authenticated user can view announcement', function () {
    (new TestSupports)->seed();
    $user = User::where('username', 'demo')->firstOrFail();
    $announcement = Announcement::factory()->create([
        'title' => 'Welcome Announcement',
        'description' => '<p>Hello world</p>',
    ]);

    $response = $this->actingAs($user)->get('/announcements/'.$announcement->id);

    $response->assertStatus(200);
});

