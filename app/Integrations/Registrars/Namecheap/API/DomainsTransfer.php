<?php

namespace App\Integrations\Registrars\Namecheap\API;

use App\Integrations\Registrars\Namecheap\Namecheap;
use Carbon\Carbon;

class DomainsTransfer extends Namecheap
{
    public function create($domain_name, $epp_code, $years = 1)
    {
        $this->command = 'namecheap.domains.transfer.create';
        $this->parameters = [
            'DomainName' => $domain_name,
            'Years' => $years,
            'EPPCode' => $epp_code,
            //             'PromotionCode' => '',
            //             'AddFreeWhoisguard' => 'no',
            //             'WGEnabled' => 'no',
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        $attributes = $this->response_content()->DomainTransferCreateResult->attributes();

        return [
            'DomainName' => (string) $attributes['DomainName'],
            'Transfer' => (string) $attributes['Transfer'] == 'true',
            'TransferID' => (int) $attributes['TransferID'],
            'StatusID' => (int) $attributes['StatusID'],
            'OrderID' => (int) $attributes['OrderID'],
            'TransactionID' => (int) $attributes['TransactionID'],
            'ChargedAmount' => (float) $attributes['ChargedAmount'],
            'StatusCode' => (int) $attributes['StatusCode'],
        ];
    }

    public function status($transfer_id)
    {
        $this->command = 'namecheap.domains.transfer.getStatus';
        $this->parameters = [
            'TransferID' => $transfer_id,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        $attributes = $this->response_content()->DomainTransferGetStatusResult->attributes();

        return [
            'TransferID' => (int) $attributes['TransferID'],
            'StatusID' => (int) $attributes['StatusID'],
            'Status' => (string) $attributes['Status'],
        ];
    }

    public function updateStatus($sld, $tld, $nameserver)
    {
        $this->command = 'namecheap.domains.transfer.updateStatus';
        $this->parameters = [
            'TransferID' => $transfer_id,
            'Resubmit' => $resubmit,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function list($list_type = 'ALL', $search_term = '', $page = 1, $page_size = 100, $sort_by = 'TRANSFERDATE_DESC')
    {
        $this->command = 'namecheap.domains.transfer.getList';
        $this->parameters = [
            'ListType' => $list_type,
            'SearchTerm' => $search_term,
            'Page' => $page,
            'PageSize' => $page_size,
            'SortBy' => $sort_by,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {
            $nc_transfers = $this->response_content()->TransferGetListResult;
            $paging = $this->response_content()->Paging;

            foreach ($nc_transfers->Transfer as $nc_transfer) {
                $attributes = $nc_transfer->attributes();

                $transfer_date = Carbon::createFromFormat('m/d/Y H:i:s', (string) $attributes['TransferDate'].' 00:00:00')->toDateTimeString();
                $status_date = Carbon::createFromFormat('m/d/Y H:i:s', (string) $attributes['StatusDate'].' 00:00:00')->toDateTimeString();

                $transfers[] = [
                    'ID' => (int) $attributes['ID'],
                    'DomainName' => (string) $attributes['DomainName'],
                    'User' => (string) $attributes['User'],
                    'TransferDate' => $transfer_date,
                    'OrderID' => (int) $attributes['OrderID'],
                    'Status' => (string) $attributes['Status'],
                    'StatusID' => (int) $attributes['StatusID'],
                    'StatusDate' => $status_date,
                    'StatusDescription' => (string) $attributes['StatusDescription'],
                ];
            }

            return [
                'transfers' => $transfers,
                'paging' => [
                    'TotalItems' => (int) $paging->TotalItems,
                    'CurrentPage' => (int) $paging->CurrentPage,
                    'PageSize' => (int) $paging->PageSize,
                ],
            ];
        }

        return null;
    }
}
