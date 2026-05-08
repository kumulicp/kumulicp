<?php

use App\Announcement;
use App\User;

describe('Announcements - Admin Management', function () {
    beforeEach(function () {
        $this->actingAs(User::find(1));
    });

    it('shows the announcements list page', function () {
        visit('/admin/service/announcements')
            ->assertSee('Announcements')
            ->assertSee('Add Announcement');
    });

    it('adds a new announcement via the modal', function () {
        visit('/admin/service/announcements')
            ->click('#addAnnouncement')
            ->fill('#title input', 'New Feature Release')
            ->click('#submit')
            ->assertSee('New Feature Release');
    });

    it('shows a validation error when creating an announcement without a title', function () {
        visit('/admin/service/announcements')
            ->click('#addAnnouncement')
            ->click('#submit')
            ->assertSee('The title field is required');
    });

    it('edits an existing announcement title and summary', function () {
        $announcement = Announcement::create([
            'title' => 'Original Title',
            'short_description' => 'Original summary',
            'description' => '<p>Original content</p>',
        ]);

        visit('/admin/service/announcements/'.$announcement->id.'/edit')
            ->assertSee('Original Title')
            ->fill('#title input', 'Updated Announcement Title')
            ->fill('#shortDescription input', 'Updated summary text')
            ->click('#submit')
            ->assertSee('Updated Announcement Title');
    });

    it('shows the announcement on the list after creation', function () {
        Announcement::create([
            'title' => 'Listed Announcement',
            'short_description' => 'Visible on list',
            'description' => '<p>Content</p>',
        ]);

        visit('/admin/service/announcements')
            ->assertSee('Listed Announcement')
            ->assertSee('Visible on list');
    });

    it('removes an announcement after confirming deletion', function () {
        Announcement::create([
            'title' => 'Announcement To Delete',
            'short_description' => 'Will be removed',
            'description' => '<p>Content</p>',
        ]);

        visit('/admin/service/announcements')
            ->assertSee('Announcement To Delete')
            ->click('button:has-text("Remove")')
            ->click('button:has-text("Delete")')
            ->assertPathIs('/admin/service/announcements')
            ->assertDontSee('Announcement To Delete');
    });
});

describe('Announcements - Dashboard Display', function () {
    beforeEach(function () {
        $this->actingAs(User::find(1));
    });

    it('shows announcements on the organization dashboard', function () {
        Announcement::create([
            'title' => 'Dashboard Announcement',
            'short_description' => 'Shown on dashboard',
            'description' => '<p>Dashboard content</p>',
        ]);

        visit('/')
            ->assertSee('Dashboard Announcement');
    });

    it('shows only the five most recent announcements on the dashboard', function () {
        foreach (range(1, 6) as $i) {
            Announcement::factory()->create([
                'title' => "Announcement {$i}",
                'short_description' => "Summary {$i}",
                'created_at' => now()->addMinutes($i),
            ]);
        }

        visit('/')
            ->assertSee('Announcement 2')
            ->assertSee('Announcement 6')
            ->assertDontSee('Announcement 1');
    });

    it('navigates to the full announcement page from the dashboard', function () {
        $announcement = Announcement::create([
            'title' => 'Full Read Announcement',
            'short_description' => 'A brief preview',
            'description' => '<p>Full announcement content here</p>',
        ]);

        visit('/')
            ->assertSee('Full Read Announcement')
            ->click('Full Read Announcement')
            ->click('a[href="/announcements/'.$announcement->id.'"]')
            ->assertPathIs('/announcements/'.$announcement->id)
            ->assertSee('Full Read Announcement')
            ->assertSee('Full announcement content here');
    });

    it('displays the correct title and content when viewing an announcement directly', function () {
        $announcement = Announcement::create([
            'title' => 'Detailed Announcement',
            'short_description' => 'Short preview',
            'description' => '<p>This is the full announcement body</p>',
        ]);

        visit('/announcements/'.$announcement->id)
            ->assertSee('Detailed Announcement')
            ->assertSee('This is the full announcement body');
    });
});

describe('Announcements - Unauthenticated Access', function () {
    it('redirects to login when viewing an announcement while unauthenticated', function () {
        $announcement = Announcement::create([
            'title' => 'Protected Announcement',
            'short_description' => 'Auth required',
            'description' => '<p>Content</p>',
        ]);

        visit('/announcements/'.$announcement->id)
            ->assertPathIs('/login');
    });
});
