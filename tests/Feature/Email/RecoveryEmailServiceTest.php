<?php

namespace Tests\Feature\Email;

use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailService;
use App\UserRecoveryEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\TestCase;

class RecoveryEmailServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeRecoveryEmailDirectory $directory;

    private RecoveryEmailService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new FakeRecoveryEmailDirectory;
        $this->app->instance(RecoveryEmailDirectory::class, $this->directory);
        $this->service = $this->app->make(RecoveryEmailService::class);
    }

    public function test_set_saves_a_lowercased_address_and_mirrors_it_to_the_directory()
    {
        $this->directory->entries = ['jane' => null];

        $this->service->set('jane', '  Jane@Example.com ');

        $this->assertSame('jane@example.com', $this->service->get('jane'));
        $this->assertSame('jane@example.com', $this->directory->entries['jane']);
    }

    public function test_set_replaces_an_existing_address_instead_of_adding_another()
    {
        $this->directory->entries = ['jane' => null];

        $this->service->set('jane', 'old@example.com');
        $this->service->set('jane', 'new@example.com');

        $this->assertSame(1, UserRecoveryEmail::where('username', 'jane')->count());
        $this->assertSame('new@example.com', $this->directory->entries['jane']);
    }

    public function test_a_blank_address_removes_it_from_the_database_and_the_directory()
    {
        $this->directory->entries = ['jane' => null];
        $this->service->set('jane', 'jane@example.com');

        $this->service->set('jane', '   ');

        $this->assertNull($this->service->get('jane'));
        $this->assertNull($this->directory->entries['jane']);
    }

    public function test_forget_removes_the_address_from_the_database_and_the_directory()
    {
        $this->directory->entries = ['jane' => null];
        $this->service->set('jane', 'jane@example.com');

        $this->service->forget('jane');

        $this->assertDatabaseMissing('user_recovery_emails', ['username' => 'jane']);
        $this->assertNull($this->directory->entries['jane']);
    }

    public function test_set_only_saves_to_the_database_when_there_is_no_directory_to_mirror_to()
    {
        $this->directory->enabled = false;
        $this->directory->entries = ['jane' => null];

        $this->service->set('jane', 'jane@example.com');

        $this->assertSame('jane@example.com', $this->service->get('jane'));
        $this->assertSame([], $this->directory->writes);
    }

    public function test_a_directory_that_fails_does_not_undo_the_save()
    {
        $this->directory->entries = ['jane' => null];
        $this->directory->failing = ['jane'];

        $this->service->set('jane', 'jane@example.com');

        $this->assertSame('jane@example.com', $this->service->get('jane'));
    }

    public function test_sync_makes_the_directory_match_the_database()
    {
        UserRecoveryEmail::create(['username' => 'drifted', 'email' => 'right@example.com']);
        UserRecoveryEmail::create(['username' => 'in-sync', 'email' => 'same@example.com']);
        UserRecoveryEmail::create(['username' => 'empty', 'email' => 'fill@example.com']);
        $this->directory->entries = [
            'drifted' => 'wrong@example.com',
            'in-sync' => 'SAME@example.com',
            'empty' => null,
        ];

        $result = $this->service->sync();

        $this->assertTrue($result['enabled']);
        $this->assertSame(3, $result['checked']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame('right@example.com', $this->directory->entries['drifted']);
        $this->assertSame('fill@example.com', $this->directory->entries['empty']);
        $this->assertSame([['drifted', 'right@example.com'], ['empty', 'fill@example.com']], $this->directory->writes);
    }

    public function test_sync_reports_accounts_missing_from_the_directory_without_stopping()
    {
        UserRecoveryEmail::create(['username' => 'gone', 'email' => 'gone@example.com']);
        UserRecoveryEmail::create(['username' => 'here', 'email' => 'here@example.com']);
        $this->directory->entries = ['here' => null];

        $result = $this->service->sync();

        $this->assertSame(['gone'], $result['missing']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('here@example.com', $this->directory->entries['here']);
    }

    public function test_sync_reports_the_accounts_it_fails_for_and_carries_on()
    {
        UserRecoveryEmail::create(['username' => 'broken', 'email' => 'broken@example.com']);
        UserRecoveryEmail::create(['username' => 'fine', 'email' => 'fine@example.com']);
        $this->directory->entries = ['broken' => null, 'fine' => null];
        $this->directory->failing = ['broken'];

        $result = $this->service->sync();

        $this->assertSame(['broken' => 'The directory is unavailable'], $result['failed']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('fine@example.com', $this->directory->entries['fine']);
    }

    public function test_sync_reports_directory_values_the_database_does_not_have_but_leaves_them_alone()
    {
        $this->directory->entries = ['old-account' => 'kept@example.com'];

        $result = $this->service->sync();

        $this->assertSame(['old-account'], $result['orphans']);
        $this->assertSame('kept@example.com', $this->directory->entries['old-account']);
        $this->assertDatabaseCount('user_recovery_emails', 0);
    }

    public function test_sync_does_nothing_when_there_is_no_directory_to_sync_with()
    {
        $this->directory->enabled = false;
        UserRecoveryEmail::create(['username' => 'jane', 'email' => 'jane@example.com']);
        $this->directory->entries = ['jane' => null];

        $result = $this->service->sync();

        $this->assertFalse($result['enabled']);
        $this->assertSame(0, $result['checked']);
        $this->assertSame([], $this->directory->writes);
    }
}
