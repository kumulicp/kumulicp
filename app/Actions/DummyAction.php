<?php

namespace App\Actions;

use App\Notifications\DummyNotification;
use App\Organization;
use App\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DummyAction extends Action
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

    public static function run($task)
    {
        Log::info(__('actions.dummy.run'), ['organization_id' => $task->organization->id]);
    }

    public static function retry($task)
    {
        Log::info(__('actions.dummy.retry'), ['organization_id' => $task->organization->id]);

        return new self;
    }

    public static function complete(Task $task)
    {
        Log::critical(__('actions.dummy.completed', ['organization_id' => $task->organization->id]));
        $task->organization->notifyAdmins(new DummyNotification($task));
        $task->complete();
        $task->groupNotified();
    }
}
