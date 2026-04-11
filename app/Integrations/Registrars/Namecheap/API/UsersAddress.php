<?php

namespace App\Integrations\Registrars\Namecheap\API;

use App\Integrations\Registrars\Namecheap\Namecheap;

class UsersAddress extends Namecheap
{
    public function create($sld, $tld, $nameserver, $ip)
    {
        $this->command = 'namecheap.users.address.create';
        $this->parameters = [
            'AddressName' => $address_name,
            'EmailAddress' => $email_address,
            'FirstName' => $first_name,
            'LastName' => $last_name,
            'Address1' => $address_1,
            'City' => $city,
            'StateProvince' => $state_province,
            'StateProvinceChoice' => $state_province_choice,
            'Zip' => $zip,
            'Country' => $country,
            'Phone' => $phone,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function delete($address_id)
    {
        $this->command = 'namecheap.users.address.delete';
        $this->parameters = [
            'AddressId' => $address_id,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function info($address_id)
    {
        $this->command = 'namecheap.users.address.info';
        $this->parameters = [
            'AddressId' => $address_id,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function list($address_id, $address_name)
    {
        $this->command = 'namecheap.domains.transfer.getList';
        $this->parameters = [
            'AddressId' => $address_id,
            'AddressName' => $address_name,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function setDefault($address_id)
    {
        $this->command = 'namecheap.users.address.setDefault';
        $this->parameters = [
            'AddressId' => $address_id,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function update($address_id, $address_name)
    {
        $this->command = 'namecheap.users.address.update';
        $this->parameters = [
            'AddressId' => $address_id,
            'AddressName' => $address_name,
            'EmailAddress' => $email_address,
            'FirstName' => $first_name,
            'LastName' => $last_name,
            'Address1' => $address_1,
            'City' => $city,
            'StateProvince' => $state_province,
            'StateProvinceChoice' => $state_province_choice,
            'Zip' => $zip,
            'Country' => $country,
            'Phone' => $phone,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }
}
