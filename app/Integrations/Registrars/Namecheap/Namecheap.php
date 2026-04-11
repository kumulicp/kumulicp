<?php

namespace App\Integrations\Registrars\Namecheap;

use App\Integrations\Integration;
use App\Organization;
use Illuminate\Support\Facades\Http;

class Namecheap extends Integration
{
    public $name = 'Namecheap';

    public $request_path = '';

    public $parameters = [];

    public $command;

    public function __construct(Organization $organization)
    {
        $this->organization = $organization;

        parent::__construct($organization);
    }

    private function baseURI()
    {
        return config('domains.registrars.namecheap.url');
    }

    public function basePath()
    {
        return $this->baseURI().$this->request_path;
    }

    private function getGlobalParameters()
    {
        return [
            'ApiUser' => config('domains.registrars.namecheap.api_user'),
            'ApiKey' => config('domains.registrars.namecheap.api_key'),
            'UserName' => config('domains.registrars.namecheap.username'),
            'ClientIp' => config('domains.registrars.namecheap.client_ip'),
        ];
    }

    public function parameters()
    {
        $list = [];

        foreach ($this->getGlobalParameters() as $key => $value) {
            $list[] = $key.'='.$value;
        }

        $list[] = 'Command='.$this->command;

        foreach ($this->parameters as $key => $value) {
            $list[] = $key.'='.$value;
        }

        return implode('&', $list);
    }

    public function postParameters()
    {
        $command = ['Command' => $this->command];

        return array_merge($this->getGlobalParameters(), $command, $this->parameters);
    }

    public function parseResponse($response)
    {
        libxml_use_internal_errors(true); // Prevents errors from non-xml responses
        $body = simplexml_load_string($response);

        if (libxml_get_errors()) {

            foreach (libxml_get_errors() as $xml_error) {

                $xml_errors[] = $xml_error->message;

            }

            $this->setError(json_encode($xml_errors), 'xml_error', fatal: true);

        }

        if (is_object($body) && count($body->Errors) > 0 && (string) $body->attributes() != 'OK') {
            foreach ($body->Errors as $error) {

                $attributes = $error->Error->attributes();
                $errors[] = [
                    'Number' => (int) $attributes['Number'],
                    'Description' => (string) $error->Error,
                ];
            }

        }

        if (isset($errors) && count($errors) > 0) {
            $this->setError(json_encode($errors), 'namecheap_errors');
        } elseif (is_object($body)) {
            $this->setResponse($body->CommandResponse);
        }
    }

    public function errorCodes()
    {
        return [
            // Domain
            '2019166' => 'Domain not found',
            '2016166' => 'Domain is not associated with your account',
            '4019337' => 'Unable to retrieve domain contacts',
            '3016166' => 'Domain is not associated with Enom',
            '3019510' => 'This domain is expired/ has transfered out/ is not associated with your account',
            '3050900' => 'Unknown response from provider',
            '5050900' => 'Unknown exceptions',
            '2033409' => 'Possibly a logical error at the authentication phase. The order chargeable for the Username is not found',
            '2033407' => 'Cannot enable domain privacy when AddWhoisguard is set to NO',
            '2033270' => 'Cannot enable domain privacy when AddWhoisguard is set to NO',
            '2015182' => 'Contact phone is invalid. The phone number format is +NNN.NNNNNNNNNN',
            '2015267' => 'EUAgreeDelete option should not be set to NO',
            '2011170' => 'Validation error from PromotionCode',
            '2011280' => 'Validation error from TLD',
            '2015167' => 'Validation error from Years',
            '2030280' => 'TLD is not supported in API',
            '2011168' => 'Nameservers are not valid',
            '2011322' => 'Extended Attributes are not valid',
            '2010323' => 'Check the required field for billing domain contacts',
            '2528166' => 'Order creation failed',
            '3019166' => 'Domain not available',
            '4019166' => 'Domain not available',
            '3031166' => 'Error while getting information from the provider',
            '3028166' => 'Error from Enom ( Errcount <> 0 )',
            '3031900' => 'Unknown response from the provider',
            '4023271' => 'Error while adding a free PositiveSSL for the domain',
            '3031166' => 'Error while getting a domain status from Enom',
            '4023166' => 'Error while adding a domain',
            '5050900' => 'Unknown error while adding a domain to your account',
            '4026312' => 'Error in refunding funds',
            '5026900' => 'Unknown exceptions error while refunding funds',
            '2515610' => 'Prices do not match',
            '2515623' => 'Domain is premium while considered regular or is regular while considered premium',
            '2005' => 'Country name is not valid',
        ];
    }

    public function testing_fakes()
    {
        Http::fake([
            /*'https://'.$this->basePath().'?'.$this->parameters() => [
                'body' => '<?xml version="1.0" encoding="UTF-8"?><ApiResponse xmlns="http://api.namecheap.com/xml.response" Status="OK"><Errors /><RequestedCommand>namecheap.domains.getTldList</RequestedCommand><CommandResponse Type="namecheap.domains.getTldList"><Tlds><Tld Name="biz" NonRealTime="false" MinRegisterYears="1" MaxRegisterYears="10" MinRenewYears="1" MaxRenewYears="10" MinTransferYears="1" MaxTransferYears="10" IsApiRegisterable="true" IsApiRenewable="true" IsApiTransferable="false" IsEppRequired="false" IsDisableModContact="false" IsDisableWGAllot="false" IsIncludeInExtendedSearchOnly="false" SequenceNumber="5" Type="GTLD" IsSupportsIDN="false" Category="P">US Business</Tld><Tld Name="bz" NonRealTime="false" MinRegisterYears="1" MaxRegisterYears="10" MinRenewYears="1" MaxRenewYears="10" MinTransferYears="1" MaxTransferYears="10" IsApiRegisterable="false" IsApiRenewable="false" IsApiTransferable="false" IsEppRequired="false" IsDisableModContact="false" IsDisableWGAllot="false" IsIncludeInExtendedSearchOnly="true" SequenceNumber="11" Type="CCTLD" IsSupportsIDN="false" Category="A">BZ Country Domain</Tld><Tld Name="ca" NonRealTime="true" MinRegisterYears="1" MaxRegisterYears="10" MinRenewYears="1" MaxRenewYears="10" MinTransferYears="1" MaxTransferYears="10" IsApiRegisterable="false" IsApiRenewable="false" IsApiTransferable="false" IsEppRequired="false" IsDisableModContact="false" IsDisableWGAllot="false" IsIncludeInExtendedSearchOnly="true" SequenceNumber="7" Type="CCTLD" IsSupportsIDN="false" Category="A">Canada Country TLD</Tld><Tld Name="cc" NonRealTime="false" MinRegisterYears="1" MaxRegisterYears="10" MinRenewYears="1" MaxRenewYears="10" MinTransferYears="1" MaxTransferYears="10" IsApiRegisterable="false" IsApiRenewable="false" IsApiTransferable="false" IsEppRequired="false" IsDisableModContact="false" IsDisableWGAllot="false" IsIncludeInExtendedSearchOnly="true" SequenceNumber="9" Type="CCTLD" IsSupportsIDN="false" Category="A">CC TLD</Tld><Tld Name="co.uk" NonRealTime="false" MinRegisterYears="2" MaxRegisterYears="10" MinRenewYears="2" MaxRenewYears="10" MinTransferYears="2" MaxTransferYears="10" IsApiRegisterable="true" IsApiRenewable="false" IsApiTransferable="false" IsEppRequired="false" IsDisableModContact="false" IsDisableWGAllot="false" IsIncludeInExtendedSearchOnly="false" SequenceNumber="18" Type="CCTLD" IsSupportsIDN="false" Category="A">UK based domain</Tld></Tlds></CommandResponse><Server>IMWS-A06</Server><GMTTimeDifference>+5:30</GMTTimeDifference><ExecutionTime>0.047</ExecutionTime></ApiResponse>',
            ],*/
        ]);
    }
}
