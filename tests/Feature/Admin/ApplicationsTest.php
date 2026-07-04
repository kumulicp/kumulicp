<?php

namespace Tests\Feature\Admin;

use App\AppScreenshot;
use App\Application;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ApplicationsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $support = new TestSupports;
        $support->seed();

        return User::find(1);
    }

    private function validUpdatePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Updated App',
            'category' => 'Productivity',
            'access_type' => 'standard',
            'short_description' => 'An updated short description',
            'description' => 'An updated description',
            'domain_option' => 'none',
            'primary_domain_allowed' => false,
            'can_update_domain' => false,
        ], $overrides);
    }

    public function test_admin_can_view_the_edit_page_with_existing_values()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create([
            'slug' => 'test_app_view',
            'name' => 'Test App View',
            'category' => 'Communication',
            'access_type' => 'basic',
            'short_description' => 'Original short description',
            'description' => 'Original description',
        ]);

        $this->actingAs($user)
            ->get("/admin/apps/{$app->slug}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Applications/AppEdit')
                ->where('app.name', 'Test App View')
                ->where('app.category', 'Communication')
                ->where('app.access_type', 'basic')
                ->where('app.short_description', 'Original short description')
            );
    }

    public function test_admin_can_update_an_application()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create([
            'slug' => 'test_app_update',
            'name' => 'Old Name',
            'category' => 'Old Category',
        ]);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload([
                'name' => 'New Name',
                'category' => 'New Category',
            ]))
            ->assertRedirect("/admin/apps/{$app->slug}/edit");

        $this->assertDatabaseHas('applications', [
            'id' => $app->id,
            'name' => 'New Name',
            'category' => 'New Category',
        ]);
    }

    public function test_update_requires_name()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_req_name', 'name' => 'Original Name']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('applications', ['id' => $app->id, 'name' => 'Original Name']);
    }

    public function test_update_requires_category()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_req_category']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload(['category' => '']))
            ->assertSessionHasErrors('category');
    }

    public function test_update_requires_valid_access_type()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_req_access_type']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload(['access_type' => 'invalid']))
            ->assertSessionHasErrors('access_type');
    }

    public function test_update_requires_valid_domain_option()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_req_domain_option']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload(['domain_option' => 'invalid']))
            ->assertSessionHasErrors('domain_option');
    }

    public function test_update_can_set_a_parent_app()
    {
        $user = $this->adminUser();
        $parent = Application::factory()->create(['slug' => 'test_app_parent']);
        $app = Application::factory()->create(['slug' => 'test_app_child']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload(['parent_app' => $parent->id]))
            ->assertRedirect("/admin/apps/{$app->slug}/edit");

        $this->assertDatabaseHas('applications', ['id' => $app->id, 'parent_app_id' => $parent->id]);
    }

    public function test_update_strips_tags_from_short_description()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_strip_tags']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload([
                'short_description' => '<script>alert(1)</script>Plain text',
            ]));

        $this->assertDatabaseHas('applications', [
            'id' => $app->id,
            'short_description' => 'alert(1)Plain text',
        ]);
    }

    public function test_admin_can_upload_screenshots_for_an_application()
    {
        Storage::fake('local');
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_screenshots_upload']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload([
                'screenshots' => [
                    UploadedFile::fake()->image('screenshot-one.png'),
                    UploadedFile::fake()->image('screenshot-two.png'),
                ],
            ]))
            ->assertRedirect("/admin/apps/{$app->slug}/edit");

        $this->assertDatabaseCount('app_screenshots', 2);
        $screenshots = AppScreenshot::where('application_id', $app->id)->orderBy('display_order')->get();
        expect($screenshots)->toHaveCount(2);
        expect($screenshots[0]->display_order)->toBeLessThan($screenshots[1]->display_order);
        foreach ($screenshots as $screenshot) {
            Storage::assertExists('images/screenshots/'.$screenshot->filename);
        }
    }

    public function test_admin_can_view_the_edit_page_with_existing_screenshots()
    {
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_screenshots_view']);
        $screenshot = AppScreenshot::create([
            'application_id' => $app->id,
            'filename' => 'existing-screenshot.png',
            'display_order' => 1,
        ]);

        $this->actingAs($user)
            ->get("/admin/apps/{$app->slug}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Applications/AppEdit')
                ->where('app.screenshots.0.id', $screenshot->id)
                ->where('app.screenshots.0.url', '/images/screenshots/existing-screenshot.png')
            );
    }

    public function test_admin_can_remove_an_existing_screenshot()
    {
        Storage::fake('local');
        Storage::put('images/screenshots/to-remove.png', 'fake-image-contents');
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_screenshots_remove']);
        $screenshot = AppScreenshot::create([
            'application_id' => $app->id,
            'filename' => 'to-remove.png',
            'display_order' => 1,
        ]);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload([
                'remove_screenshots' => [$screenshot->id],
            ]))
            ->assertRedirect("/admin/apps/{$app->slug}/edit");

        $this->assertDatabaseMissing('app_screenshots', ['id' => $screenshot->id]);
        Storage::assertMissing('images/screenshots/to-remove.png');
    }

    public function test_update_rejects_non_image_screenshots()
    {
        Storage::fake('local');
        $user = $this->adminUser();
        $app = Application::factory()->create(['slug' => 'test_app_screenshots_invalid']);

        $this->actingAs($user)
            ->post("/admin/apps/{$app->slug}", $this->validUpdatePayload([
                'screenshots' => [UploadedFile::fake()->create('not-an-image.txt', 10)],
            ]))
            ->assertSessionHasErrors('screenshots.0');

        $this->assertDatabaseCount('app_screenshots', 0);
    }
}
