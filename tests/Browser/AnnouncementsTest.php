<?php

use App\Announcement;

describe('Announcements - Admin Management', function () {
    it('shows the announcements list page', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->visit('/admin/service/announcements')
            ->assertSee('Announcements')
            ->assertSee('Add Announcement');
    });

    it('adds a new announcement via the modal', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->visit('/admin/service/announcements')
            ->click('#addAnnouncement')
            ->fill('#title', 'New Feature Release')
            ->click('#submit')
            ->assertSee('New Feature Release');
    });

    it('shows a validation error when creating an announcement without a title', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->visit('/admin/service/announcements')
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

        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->visit('/admin/service/announcements/'.$announcement->id.'/edit')
            ->assertSee('Original Title')
            ->fill('#title', 'Updated Announcement Title')
            ->fill('#shortDescription', 'Updated summary text')
            ->click('#submit')
            ->assertSee('Updated Announcement Title');
    });

    it('shows the announcement on the list after creation', function () {
        $announcement = Announcement::create([
            'title' => 'Listed Announcement',
            'short_description' => 'Visible on list',
            'description' => '<p>Content</p>',
        ]);

        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->visit('/admin/service/announcements')
            ->assertSee('Listed Announcement')
            ->assertSee('Visible on list');
    });

    it('removes an announcement after confirming deletion', function () {
        $announcement = Announcement::create([
            'title' => 'Announcement To Delete',
            'short_description' => 'Will be removed',
            'description' => '<p>Content</p>',
        ]);

        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->visit('/admin/service/announcements')
            ->assertSee('Announcement To Delete')
            ->click('button:has-text("Remove")')
            ->click('button:has-text("Delete")')
            ->assertPathIs('/admin/service/announcements')
            ->assertDontSee('Announcement To Delete');
    });
});

describe('Announcements - Dashboard Display', function () {
    it('shows announcements on the organization dashboard', function () {
        Announcement::create([
            'title' => 'Dashboard Announcement',
            'short_description' => 'Shown on dashboard',
            'description' => '<p>Dashboard content</p>',
        ]);

        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/')
            ->assertSee('Dashboard Announcement');
    });

    it('shows only the five most recent announcements on the dashboard', function () {
        foreach (range(1, 6) as $i) {
            Announcement::create([
                'title' => "Announcement {$i}",
                'short_description' => "Summary {$i}",
                'description' => '<p>Content</p>',
            ]);
        }

        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/')
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

        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/')
            ->assertSee('Full Read Announcement')
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

        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->visit('/announcements/'.$announcement->id)
            ->assertSee('Detailed Announcement')
            ->assertSee('This is the full announcement body');
    });

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
