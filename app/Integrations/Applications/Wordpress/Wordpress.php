<?php

namespace App\Integrations\Applications\Wordpress;

use App\Integrations\Applications\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Wordpress extends Application
{
    public $name = 'Wordpress';

    public function basePath()
    {
        return $this->app_instance->address().'/wp-json/wp/v2';
    }

    public function auth()
    {
        $support_user = $this->support_user();

        return [
            $support_user,
            $this->app_instance->api_password(),
        ];
    }

    public function parseResponse($response)
    {
        if ($this->status_code == 400) {
            $response = json_decode($response, true);
            $error_message = "Description: {$this->action_description}. Response: [{$response['code']}] {$response['message']}";
            Log::critical($error_message, ['organization_id' => $this->organization->id]);

            $this->setError($error_message, $response['code']);
        } else {
            $this->setResponse(json_decode($response, true));
        }
    }

    public function testing_fakes()
    {
        Http::fake([
            // $this->basePath().'/users' => [],
        ]);
    }
}
