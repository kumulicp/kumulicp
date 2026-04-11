<?php

namespace App\Actions\Tests;

use App\AccountTest;
use App\Actions\Action;
use App\Actions\Organizations\DeleteOrganization;
use App\Organization;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

class ClearTestAccounts extends Action
{
    public $slug = 'clear_tests';

    public function __construct(AccountTest $test)
    {
        $this->organization = Organization::where('type', 'superaccount')->first();
        $this->setCustomValues(['test_id' => $test->id]);
        $this->description = __('actions.clear_test_accounts');
    }

    public static function run(Task $task)
    {
        $test = AccountTest::find($task->getValue('test_id'));
        $waiting_for = [];

        foreach ($test->organizations as $organization) {
            $action = ActionFacade::execute(new DeleteOrganization($organization), $task);
            $waiting_for[] = $action->id;
        }

        $self = new self($test);
        $self->addCustomValue(['waiting_for' => $waiting_for]);

        return $self;
    }

    public static function complete(Task $task)
    {
        $test = AccountTest::find($task->getValue('test_id'));

        $test->status = 'cleared';
        $test->save();

        $task->complete();
        $task->notified();
    }
}
