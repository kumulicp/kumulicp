<?php

namespace App\Integrations\Registrars\Namecheap\API;

use App\Integrations\Registrars\Namecheap\Namecheap;
use Illuminate\Support\Facades\Crypt;

class Users extends Namecheap
{
    public function pricing($return = 'prices', $product_name = 'COM', $action_name = '', $promotion_code = '', $product_category = 'DOMAINS', $product_type = 'DOMAIN')
    {
        $this->command = 'namecheap.users.getPricing';
        $this->parameters = [
            'ProductType' => $product_type,
            'ProductCategory' => $product_category,
            'PromotionCode' => $promotion_code,
            'ActionName' => $action_name,
            'ProductName' => $product_name,
        ];

        try {
            $this->form()->post($this->basePath(), $this->postParameters());
        } catch (\Throwable $e) {

        }

        if (! $this->hasError()) {
            $results = $this->response_content()->UserGetPricingResult;
            $products = [];
            $categories = [];

            foreach ($results->ProductType as $type) {
                $type_attributes = $type->attributes();

                $type_name = (string) $type_attributes['Name'];

                foreach ($type->ProductCategory as $category) {
                    $category_attributes = $category->attributes();

                    $category_name = (string) $category_attributes['Name'];

                    foreach ($category->Product as $product) {
                        $product_attributes = $product->attributes();

                        $product_name = (string) $product_attributes['Name'];

                        foreach ($product->Price as $price) {
                            $price_attributes = $price->attributes();

                            $duration = (int) $price_attributes['Duration'];

                            $prices[$duration] = [
                                'Duration' => (int) $price_attributes['Duration'],
                                'DurationType' => (string) $price_attributes['DurationType'],
                                'Price' => (float) $price_attributes['Price'],
                                'RegularPrice' => (float) $price_attributes['RegularPrice'],
                                'YourPrice' => (float) $price_attributes['YourPrice'],
                                'CouponPrice' => (float) $price_attributes['CouponPrice'],
                                'Currency' => (string) $price_attributes['Currency'],
                            ];
                        }

                        $products[$product_name] = $prices;
                    }

                    $categories[$category_name] = $products;
                }

                $types[$type_name] = $categories;
            }

            return $$return;
        }

        return null;
    }

    public function balances($sld, $tld, $name_server)
    {
        $this->command = 'namecheap.users.balances';
        $this->parameters = [
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function changePassword($old_password, $new_password)
    {
        $this->command = 'namecheap.users.getList';
        $this->parameters = [
            'OldPassword' => $old_password,
            'NewPassword' => $new_password,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function update($sld, $tld)
    {
        $this->command = 'namecheap.users.update';
        $this->parameters = [
            'FirstName' => $first_name,
            'LastName' => $last_name,
            'Address1' => $address_1,
            'City' => $city,
            'StateProvince' => $state_province,
            'Zip' => $zip,
            'Country' => $country,
            'EmailAddress' => $email_address,
            'Phone' => $phone,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function createAddFundsRequest($domain_name)
    {
        $this->command = 'namecheap.users.createaddfundsrequest';
        $this->parameters = [
            'Username' => $username,
            'PaymentType' => $payment_type,
            'Amount' => $amount,
            'ReturnUrl' => $return_url,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function addFundsStatus($token_id)
    {
        $this->command = 'namecheap.users.getAddFundsStatus';
        $this->parameters = [
            'TokenId' => $token_id,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function create()
    {
        $organization = $this->organization;
        $namecheap = $this->namecheap;
        $this->username = null;

        $password = Crypt::decrypString($namecheap->password);

        $this->command = 'namecheap.users.create';
        $this->parameters = [
            'NewUserName' => $organization->slug,
            'NewUserPassword' => $password,
            'EmailAddress' => $namecheap->email_address,
            'FirstName' => $namecheap->first_name,
            'LastName' => $namecheap->last_name,
            'AcceptTerms' => 1,
            'Address1' => $namecheap->address_1,
            'City' => $namecheap->city,
            'StateProvince' => $namecheap->state_province,
            'Zip' => $namecheap->zip,
            'Country' => $namecheap->country,
            'Phone' => $namecheap->phone,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function login($password)
    {
        $this->username = $this->organization->namecheap->username;

        $this->command = 'namecheap.users.login';
        $this->parameters = [
            'Password' => $password,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function resetPassword($domain_name, $hosts)
    {
        $this->command = 'namecheap.users.resetPassword';
        $this->parameters = [
            'FindBy' => $find_by,
            'FindByValue' => $find_by_value,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }
}
