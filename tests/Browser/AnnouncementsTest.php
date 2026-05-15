<?php

use App\Announcement;
use App\User;

describe('Announcements - Admin Management', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('adds a new announcement and shows it in the list', function () {
        visit('/admin/service/announcements')
            ->assertSee('Announcements')
            ->assertSee('Add Announcement')
            ->click('#addAnnouncement')
            ->fill('#title input', 'New Feature Release')
            ->click('#submit')
            ->assertSee('New Feature Release');
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
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('shows announcements on the dashboard and navigates to the full announcement page', function () {
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
