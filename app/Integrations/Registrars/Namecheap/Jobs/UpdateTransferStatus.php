<?php

namespace App\Integrations\Registrars\Namecheap\Jobs;

use App\Actions\Domains\UpdateDnsRecords;
use App\Actions\Organizations\InvoiceOrganization;
use App\Integrations\Registrars\Namecheap\API\DomainsTransfer;
use App\Notifications\DomainTransferred;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Action;
use Illuminate\Support\Facades\Log;

class UpdateTransferStatus
{
    public function __invoke()
    {
        $organization = Organization::where('type', 'superaccount')->first();
        $domains_transferring = OrgDomain::where('status', 'transferring')->whereNotNull('transfer_id')->get();

        if (count($domains_transferring) > 0) {
            $domains_transfer = new DomainsTransfer($organization);
            if ($transfers = $domains_transfer->list()) {

                foreach ($transfers['transfers'] as $transfer) {

                    // Set proper domain status
                    switch ($transfer['Status']) {
                        case 'CANCELED':
                            $status = 'deactivated';
                            break;
                        case 'CANCELLED':
                            $status = 'deactivated';
                            break;
                        case 'INPROGRESS':
                            $status = 'transferring';
                            break;
                        case 'WAITINGFOREPP':
                            $status = 'transferring';
                            break;
                        case 'COMPLETED':
                            $status = 'active';
                            break;
                        default:
                            $status = 'transferring';
                    }

                    // Determine whether to post Namecheap message or show generic error message
                    if ($this->isUserMessage($transfer['StatusID'])) {
                        $status_description = $transfer['StatusDescription'];
                    } else {
                        $status_description = __('organization.domain.denied.transferring');
                    }

                    $org_domain = OrgDomain::where('name', $transfer['DomainName'])
                        ->where('status', 'transferring')
                        ->where('transfer_id', $transfer['ID'])
                        ->with('organization')
                        ->first();

                    if ($org_domain && $org_domain->status != 'deactivated') {

                        // TODO: Get and set domain_id
                        $org_domain->status = $status;
                        $org_domain->status_description = $status_description;
                        $org_domain->status_id = $transfer['StatusID'];
                        $org_domain->save();

                        // If is a critical error or status id isn't in my list, submit a critical log
                        if ($this->isCriticalError($transfer['StatusID']) || (! $this->isCriticalError($transfer['StatusID']) && ! $this->isUserMessage($transfer['StatusID']))) {
                            Log::critical("Transfering {$transfer['DomainName']}: ({$transfer['StatusID']}) {$transfer['StatusDescription']}", ['organization_id' => $organization->id]);
                        } elseif ($transfer['Status'] == 'COMPLETED') {

                            $org_domain->registered_at = $transfer['TransferDate'];
                            $org_domain->transferred_at = $transfer['TransferDate'];
                            $org_domain->save();

                            // Setup DNS records for domain
                            Action::execute(new UpdateDnsRecords($org_domain->organization, $org_domain));

                            // Charge organization for domain name
                            Action::execute(new InvoiceOrganization($org_domain->organization, __('actions.notify.domain_transfer_fee'), $price), $task);

                            // Notify admins that transfer complete.
                            $organization->notifyAdmins(new DomainTransferred);
                        }
                    }
                }
            }
        }
    }

    private function isCriticalError($status_id)
    {
        $critical_status_ids = [
            2,
            6,
            7,
            8,
            24,
            36,
            45,
            -7, // Canceled - Domain cannot be transferred within the same registrar
        ];

        return in_array($status_id, $critical_status_ids);

    }

    private function isUserMessage($status_id)
    {
        $user_status_ids = [
            0,
            1,
            2,
            3,
            4,
            5,
            10,
            11,
            12,
            13,
            14,
            15,
            16,
            17,
            18,
            20,
            21,
            22,
            23,
            24,
            25,
            26,
            27,
            28,
            29,
            30,
            31,
            32,
            33,
            34,
            35,
            45,
            48,
            49,
            50,
            51,
            -4,
            -22,
            -1,
            -5,
            -2
            - 202,
            -22,
            -7, // Canceled - Domain cannot be transferred within the same registrar
        ];

        return in_array($status_id, $user_status_ids);

    }
}
