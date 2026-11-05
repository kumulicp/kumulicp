<?php

use App\Actions\Action;
use App\Organization;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestSupports;

/**
 * Identical open tasks: one gets created, however many requests arrive, and
 * only the unique index on tasks.dedup_open has to be right for that.
 */
class DedupAction extends Action
{
    public $slug = 'dedup_action';

    public function __construct(Organization $organization, array $values = [], public bool $dedup = true)
    {
        $this->organization = $organization;
        $this->description = 'Dedup test';
        $this->setCustomValues($values);
    }

    public function dedupKey(): ?string
    {
        return $this->dedup ? parent::dedupKey() : null;
    }
}

class DedupReplacingAction extends DedupAction
{
    public $slug = 'dedup_replacing_action';

    public $replace = true;
}

function dedupOrganization(): Organization
{
    (new TestSupports)->seed();
    Queue::fake();

    return Organization::find(1);
}

it('creates one task for two identical requests', function () {
    $organization = dedupOrganization();

    $first = ActionFacade::execute(new DedupAction($organization, ['a' => 1]));
    $second = ActionFacade::execute(new DedupAction($organization, ['a' => 1]));

    expect($first)->toBeInstanceOf(Task::class);
    expect($second)->toBeNull();
    expect(Task::where('action_slug', 'dedup_action')->count())->toBe(1);
});

it('treats different values and organizations as different tasks', function () {
    $organization = dedupOrganization();

    $a = ActionFacade::execute(new DedupAction($organization, ['a' => 1]));
    $b = ActionFacade::execute(new DedupAction($organization, ['a' => 2]));
    $other_org = ActionFacade::execute(new DedupAction(Organization::factory()->create(), ['a' => 1]));

    expect([$a, $b, $other_org])->each->toBeInstanceOf(Task::class);
});

it('puts the app instance in the key', function () {
    $organization = dedupOrganization();

    $without = (new DedupAction($organization, ['a' => 1]))->dedupKey();
    $with = (new DedupAction($organization, ['a' => 1]))->setAppInstance((object) ['id' => 7])->dedupKey();
    $other = (new DedupAction($organization, ['a' => 1]))->setAppInstance((object) ['id' => 8])->dedupKey();

    expect($without)->not->toBe($with);
    expect($with)->not->toBe($other);
    expect($with)->toBe((new DedupAction($organization, ['a' => 1]))->setAppInstance((object) ['id' => 7])->dedupKey());
});

it('ignores the values when the action replaces', function () {
    $organization = dedupOrganization();

    expect((new DedupReplacingAction($organization, ['a' => 1]))->dedupKey())
        ->toBe((new DedupReplacingAction($organization, ['a' => 2]))->dedupKey());
});

it('does not care what order the values are in', function () {
    $organization = dedupOrganization();

    ActionFacade::execute(new DedupAction($organization, ['a' => 1, 'b' => ['x' => 1, 'y' => 2]]));
    $again = ActionFacade::execute(new DedupAction($organization, ['b' => ['y' => 2, 'x' => 1], 'a' => 1]));

    expect($again)->toBeNull();
});

it('lets a new task through once the identical one has finished or been cancelled, but not while it has failed', function () {
    $organization = dedupOrganization();
    $task = ActionFacade::execute(new DedupAction($organization, ['a' => 1]));

    $task->forceFill(['status' => 'failed'])->save();
    expect(ActionFacade::execute(new DedupAction($organization, ['a' => 1])))->toBeNull();

    $task->forceFill(['status' => 'complete'])->save();
    expect(ActionFacade::execute(new DedupAction($organization, ['a' => 1])))->toBeInstanceOf(Task::class);

    Task::where('action_slug', 'dedup_action')->update(['status' => 'cancelled']);
    expect(ActionFacade::execute(new DedupAction($organization, ['a' => 1])))->toBeInstanceOf(Task::class);
});

it('never treats an action without a key as a duplicate', function () {
    $organization = dedupOrganization();

    $first = ActionFacade::execute(new DedupAction($organization, ['a' => 1], dedup: false));
    $second = ActionFacade::execute(new DedupAction($organization, ['a' => 1], dedup: false));

    expect($first)->toBeInstanceOf(Task::class);
    expect($second)->toBeInstanceOf(Task::class);
    expect($second->dedup_key)->toBeNull();
});

it('is not stopped by tasks created before the key existed', function () {
    $organization = dedupOrganization();
    $old = new Task;
    $old->forceFill(['organization_id' => $organization->id, 'action_group' => 'system', 'action_slug' => 'dedup_action', 'task_group' => 1, 'status' => 'pending', 'description' => 'Old']);
    $old->save();

    expect(ActionFacade::execute(new DedupAction($organization, ['a' => 1])))->toBeInstanceOf(Task::class);
});

it('swaps a replacing action in for the open task that has not started', function () {
    $organization = dedupOrganization();

    $first = ActionFacade::execute(new DedupReplacingAction($organization, ['a' => 1]));
    $second = ActionFacade::execute(new DedupReplacingAction($organization, ['a' => 2]));

    expect($second)->toBeInstanceOf(Task::class);
    expect(Task::find($first->id))->toBeNull();
    expect(Task::where('action_slug', 'dedup_replacing_action')->count())->toBe(1);
});

it('never deletes a task that is running, and drops the replacing request instead', function () {
    $organization = dedupOrganization();
    $running = ActionFacade::execute(new DedupReplacingAction($organization));
    $running->forceFill(['status' => 'in_progress', 'claimed_at' => now()])->save();

    $second = ActionFacade::execute(new DedupReplacingAction($organization));

    expect($second)->toBeNull();
    expect($running->fresh()->status->value)->toBe('in_progress');

    // Queued has been handed to a worker too
    $running->forceFill(['status' => 'queued', 'claimed_at' => null])->save();
    expect(ActionFacade::execute(new DedupReplacingAction($organization)))->toBeNull();
    expect(Task::find($running->id))->not->toBeNull();
});

it('keeps a unique index over the open tasks', function () {
    expect(Schema::hasColumns('tasks', ['dedup_key', 'dedup_open']))->toBeTrue();

    $index = collect(Schema::getIndexes('tasks'))->firstWhere('name', 'tasks_dedup_open_unique');

    expect($index)->not->toBeNull();
    expect($index['unique'])->toBeTrue();
    expect($index['columns'])->toBe(['dedup_open']);
});
