<?php

namespace Tests\Feature;

use App\Announcement;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        (new TestSupports)->seed();

        return User::find(1);
    }

    // Auth: unauthenticated requests are redirected to login

    public function test_guests_cannot_access_admin_announcements_index()
    {
        $response = $this->get('/admin/service/announcements');

        $response->assertRedirect('/login');
    }

    public function test_guests_cannot_create_announcement()
    {
        $response = $this->post('/admin/service/announcements', [
            'title' => 'Test Announcement',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_guests_cannot_update_announcement()
    {
        $announcement = Announcement::factory()->create();

        $response = $this->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Updated Title',
            'short_description' => 'Summary',
            'description' => '<p>Content</p>',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_guests_cannot_delete_announcement()
    {
        $announcement = Announcement::factory()->create();

        $response = $this->delete('/admin/service/announcements/'.$announcement->id);

        $response->assertRedirect('/login');
    }

    // Auth: non-admin users are forbidden

    public function test_non_admin_cannot_access_admin_announcements_index()
    {
        (new TestSupports)->seed();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/service/announcements');

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_create_announcement()
    {
        (new TestSupports)->seed();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/admin/service/announcements', [
            'title' => 'Test Announcement',
        ]);

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_update_announcement()
    {
        (new TestSupports)->seed();
        $user = User::factory()->create();
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($user)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Updated Title',
            'short_description' => 'Summary',
            'description' => '<p>Content</p>',
        ]);

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_delete_announcement()
    {
        (new TestSupports)->seed();
        $user = User::factory()->create();
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($user)->delete('/admin/service/announcements/'.$announcement->id);

        $response->assertForbidden();
    }

    // Validation: store

    public function test_store_requires_title()
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->post('/admin/service/announcements', [
            'title' => '',
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_store_title_cannot_exceed_255_characters()
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->post('/admin/service/announcements', [
            'title' => str_repeat('a', 256),
        ]);

        $response->assertSessionHasErrors('title');
    }

    // Validation: update

    public function test_update_requires_title()
    {
        $user = $this->adminUser();
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($user)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => '',
            'short_description' => 'Summary',
            'description' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_update_requires_short_description()
    {
        $user = $this->adminUser();
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($user)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Valid Title',
            'short_description' => '',
            'description' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('short_description');
    }

    public function test_update_requires_description()
    {
        $user = $this->adminUser();
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($user)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => 'Valid Title',
            'short_description' => 'Summary',
            'description' => '',
        ]);

        $response->assertSessionHasErrors('description');
    }

    public function test_update_title_cannot_exceed_255_characters()
    {
        $user = $this->adminUser();
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($user)->put('/admin/service/announcements/'.$announcement->id, [
            'title' => str_repeat('a', 256),
            'short_description' => 'Summary',
            'description' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('title');
    }

    // CRUD: successful operations

    public function test_admin_can_create_announcement()
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->post('/admin/service/announcements', [
            'title' => 'New Announcement',
        ]);

        $response->assertRedirectContains('/admin/service/announcements/');
        $response->assertRedirectContains('/edit');
        $this->assertDatabaseHas('announcements', ['title' => 'New Announcement']);
    }

    public function test_admin_can_update_announcement()
    {
        $user = $this->adminUser();
        $announcement = Announcement::factory()->create(['title' => 'Old Title']);

        $response = $this->actingAs($user)->put('/admin/service/announcements/'.$announcement->id, [
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
    }

    public function test_admin_can_delete_announcement()
    {
        $user = $this->adminUser();
        $announcement = Announcement::factory()->create();

        $response = $this->actingAs($user)->delete('/admin/service/announcements/'.$announcement->id);

        $response->assertRedirect('/admin/service/announcements');
        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    // User announcement view

    public function test_authenticated_user_can_view_announcement()
    {
        (new TestSupports)->seed();
        $user = User::find(1);
        $announcement = Announcement::factory()->create([
            'title' => 'Welcome Announcement',
            'description' => '<p>Hello world</p>',
        ]);

        $response = $this->actingAs($user)->get('/announcements/'.$announcement->id);

        $response->assertStatus(200);
    }

    public function test_guest_cannot_view_announcement()
    {
        $announcement = Announcement::factory()->create();

        $response = $this->get('/announcements/'.$announcement->id);

        $response->assertRedirect('/login');
    }
}
