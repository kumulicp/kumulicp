<?php

namespace Database\Factories;

use App\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => 'Rancher',
            'address' => 'https://'.env('RANCHER_HOST'),
            'host' => 'https://'.env('RANCHER_HOST'),
            'api_key' => env('RANCHER_API_KEY') ?? 'api_key',
            'api_secret' => env('RANCHER_API_SECRET') ?? 'api_secret',
            'default_web_server' => 1,
            'internal_address' => 'localhost',
            'type' => 'web',
            'interface' => 'rancher',
            'settings' => '{"project_id":"'.env('RANCHER_PROJECT_ID').'"}',
            'ip' => '127.0.0.1',
            'status' => 'active',
        ];
    }
}
