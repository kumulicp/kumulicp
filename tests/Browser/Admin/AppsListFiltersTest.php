<?php

use App\Application;
use App\Enums\ApplicationSource;
use App\Tag;
use App\User;

describe('Admin Apps List Filters', function () {
    beforeEach(function () {
        Application::factory()->create(['slug' => 'filter_on', 'name' => 'Alpha Enabled', 'enabled' => true, 'source' => ApplicationSource::Generic]);
        Application::factory()->create(['slug' => 'filter_off', 'name' => 'Beta Disabled', 'enabled' => false, 'source' => ApplicationSource::HelmForge])
            ->tags()->attach(Tag::findOrCreateMany(['wiki']));

        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('lists enabled apps by default and remembers the filters that were last used', function () {
        $page = visit('/admin/apps')
            ->assertSee('Alpha Enabled')
            ->assertDontSee('Beta Disabled')
            ->click('#filterStatus')
            ->click('text=All')
            ->assertSee('Alpha Enabled')
            ->assertSee('Beta Disabled')
            ->assertSee('wiki');

        // Back on the plain URL the previous choice is restored
        $page->navigate('/admin/apps')
            ->assertSee('Beta Disabled')
            ->assertSee('Alpha Enabled');

        $page->click('#resetFilters')
            ->assertSee('Alpha Enabled')
            ->assertDontSee('Beta Disabled');

        $page->navigate('/admin/apps')
            ->assertSee('Alpha Enabled')
            ->assertDontSee('Beta Disabled');
    });
});
