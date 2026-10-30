<?php

namespace App\Actions;

use App\Notifications\DummyNotification;
use App\Organization;
use App\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DummyAction extends NativeAction
{
    public $slug = 'dummy_action';

    public $domain;

    public function __construct()
    {
        $random = Str::random(3);
        $this->organization = Organization::where('type', 'superaccount')->first();

        Log::info(__('actions.dummy.construct'), ['organization_id' => $this->organization->id]);
        $this->setCustomValues(['id' => $random]);
        $this->description = 'Dummy Task';
    }

    public static function handle(Task $task): ActionResult
    {
        Log::info(__('actions.dummy.run'), ['organization_id' => $task->organization->id]);

        return ActionResult::wait();
    }

    public static function retry(Task $task): ?Action
    {
        Log::info(__('actions.dummy.retry'), ['organization_id' => $task->organization->id]);

        return new self;
    }

    public static function poll(Task $task): ActionResult
    {
        Log::critical(__('actions.dummy.completed', ['organization_id' => $task->organization->id]));
        $task->organization->notifyAdmins(new DummyNotification($task));

        return ActionResult::done();
    }
}
