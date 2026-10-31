<?php

use App\AuditLog;
use App\Organization;
use App\Support\Facades\Audit;
use App\Support\Facades\Settings;
use App\Task;
use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestSupports;

/*
|--------------------------------------------------------------------------
| Audit trail
|--------------------------------------------------------------------------
|
| Auditing is opt in: nothing may be recorded until the "audit_logging_enabled"
| server setting is on, apart from the act of switching it on or off.
*/

beforeEach(function () {
    (new TestSupports)->seed();
    Cache::flush();
});

function enable_audit(): void
{
    Settings::update('audit_logging_enabled', '1');
}

it('is off by default and records nothing', function () {
    expect(Audit::enabled())->toBeFalse()
        ->and(Audit::record('something.happened'))->toBeNull();

    Settings::update('support_email', 'help@example.com');

    expect(AuditLog::count())->toBe(0);
});

it('always records switching auditing on and off', function () {
    Settings::update('audit_logging_enabled', '1');

    expect(Audit::enabled())->toBeTrue()
        ->and(AuditLog::where('event', 'audit.enabled')->count())->toBe(1);

    Settings::update('audit_logging_enabled', null);

    expect(Audit::enabled())->toBeFalse()
        ->and(AuditLog::where('event', 'audit.disabled')->count())->toBe(1);

    // And nothing else is recorded once it is off again.
    Settings::update('support_email', 'help@example.com');
    expect(AuditLog::count())->toBe(2);
});

it('records the acting user, subject and request details', function () {
    enable_audit();
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    $entry = Audit::record('thing.done', ['type' => 'widget', 'id' => 7, 'label' => 'Seven'], properties: ['note' => 'hello']);

    expect($entry->actor_type)->toBe('user')
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->actor_label)->toBe($user->email)
        ->and($entry->actor_organization_id)->toBe($user->organization->id)
        ->and($entry->subject_type)->toBe('widget')
        ->and($entry->subject_id)->toBe('7')
        ->and($entry->outcome)->toBe('success')
        ->and($entry->properties)->toBe(['note' => 'hello'])
        ->and($entry->event_id)->not->toBeEmpty();
});

it('masks secrets in diffs and properties', function () {
    enable_audit();

    $entry = Audit::record('thing.changed', diff: ['api_token' => ['old' => 'aaa', 'new' => 'bbb']], properties: ['client_secret' => 'ccc', 'ok' => 'visible']);

    expect(json_encode($entry->diff))->not->toContain('aaa')->not->toContain('bbb')
        ->and(json_encode($entry->properties))->not->toContain('ccc')->toContain('visible');
});

it('records setting changes with old and new values and masks sensitive settings', function () {
    enable_audit();

    Settings::update('support_email', 'one@example.com');
    Settings::update('support_email', 'two@example.com');
    Settings::update('ldap_password', 'hunter2-password');

    $changes = AuditLog::where('event', 'settings.changed')->orderBy('id')->get();

    expect($changes)->toHaveCount(3)
        ->and($changes[1]->diff['support_email'])->toBe(['old' => 'one@example.com', 'new' => 'two@example.com'])
        ->and(json_encode($changes[2]->diff))->not->toContain('hunter2');
});

it('does not record a setting that was saved unchanged', function () {
    Settings::update('support_email', 'one@example.com');
    enable_audit();

    Settings::update('support_email', 'one@example.com');

    expect(AuditLog::where('event', 'settings.changed')->count())->toBe(0);
});

it('records create, update and delete of allow listed models and ignores the rest', function () {
    enable_audit();
    $organization = Organization::where('slug', 'demo')->firstOrFail();

    $organization->name = 'Renamed Org';
    $organization->save();

    $entry = AuditLog::where('event', 'organization.updated')->firstOrFail();

    expect($entry->diff['name']['new'])->toBe('Renamed Org')
        ->and($entry->target_organization_id)->toBe($organization->id)
        ->and($entry->diff)->not->toHaveKey('updated_at');

    // Task is deliberately not in config('audit.models').
    expect(AuditLog::where('subject_type', Task::class)->count())->toBe(0);
});

it('hides hidden model attributes in the diff', function () {
    enable_audit();
    $user = User::where('username', 'demo')->firstOrFail();

    $user->password = 'a-new-password-hash';
    $user->save();

    $entry = AuditLog::where('event', 'user.updated')->firstOrFail();

    expect($entry->diff['password'])->toBe(['old' => '[hidden]', 'new' => '[hidden]']);
});

it('refuses to modify or delete a single entry', function () {
    enable_audit();
    $entry = Audit::record('thing.done');

    expect(fn () => $entry->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('never throws when the audit table cannot be written', function () {
    enable_audit();
    Schema::drop('audit_logs');

    expect(Audit::record('thing.done'))->toBeNull();
});

it('prunes entries past the retention period and keeps everything when none is set', function () {
    enable_audit();
    $old = Audit::record('old.thing');
    AuditLog::query()->whereKey($old->id)->toBase()->update(['occurred_at' => now()->subDays(40)]);
    Audit::record('new.thing');

    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();
    expect(AuditLog::count())->toBe(3); // enabled + old + new, nothing pruned without retention

    config(['audit.retention_days' => 30]);
    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

    expect(AuditLog::where('event', 'old.thing')->exists())->toBeFalse()
        ->and(AuditLog::where('event', 'new.thing')->exists())->toBeTrue();
});

it('copies task facts into the audit trail with the task initiator as actor', function () {
    enable_audit();
    $organization = Organization::where('slug', 'demo')->firstOrFail();
    $user = User::where('username', 'demo')->firstOrFail();

    $task = new Task;
    $task->organization_id = $organization->id;
    $task->action_slug = 'dummy';
    $task->description = 'Dummy Task';
    $task->status = 'complete';
    $task->initiated_by_type = 'user';
    $task->initiated_by_id = $user->id;
    $task->save();

    $entry = Audit::recordTaskEvent('task.completed', $task);

    expect($entry->actor_id)->toBe($user->id)
        ->and($entry->target_organization_id)->toBe($organization->id)
        ->and($entry->properties['action_slug'])->toBe('dummy');
});
