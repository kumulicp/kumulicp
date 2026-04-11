<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\Organization;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Task;
use Illuminate\Support\Facades\Log;

class InvoiceOrganization extends Action
{
    public $slug = 'invoice_organization';

    public $background = true;

    public $status = 'in_progress';

    public function __construct(Organization $organization, $description, float $price)
    {
        $this->organization = $organization;

        $this->description = __('actions.invoice_organization', ['description' => $description]);

        $this->setCustomValues(['price' => $price, 'description' => $description]);
    }

    public static function run(Task $task)
    {
        OrganizationFacade::setOrganization($task->organization);
        try {
            Billing::sendInvoice(price: $task->getValue('price'), description: $task->getValue('description'));

            Log::info(__('actions.notify.invoice', ['description' => $task->getValue('description'), 'price' => $task->getValue('price')]), ['organization_id' => $task->organization->id]);

            $task->complete();
            $task->groupNotified();
        } catch (\Exception $e) {
            $task->status = 'failed';
            $task->error_code = 'stripe_invoice_organization_failed';
            $task->error_message = __('organization.billing_manager.denied.invoice');
            $task->save();

            report($e);

            throw new \Exception($e->getMessage());
        }
    }

    public static function retry(Task $task)
    {
        return new self($task->organization, $task->getValue('description'), $task->getValue('price'));
    }

    public static function complete(Task $task) {}
}
