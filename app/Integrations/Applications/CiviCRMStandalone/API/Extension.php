<?php

namespace App\Integrations\Applications\CiviCRMStandalone\API;

use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandalone;
use Illuminate\Support\Arr;

class Extension extends CiviCRMStandalone
{
    public function installed(array $keys): bool
    {
        $path = $this->basePath().'/ajax/api4/Extension/get';

        $this->form()->post($path, $this->data([
            'select' => ['key', 'status'],
            'where' => [['key', 'IN', $keys]],
        ]));

        $response = $this->response_content();
        $values = Arr::get($response, 'values', []);

        $installed = collect($values)
            ->filter(fn ($extension) => Arr::get($extension, 'status') === 'installed')
            ->pluck('key')
            ->all();

        return count(array_intersect($keys, $installed)) === count($keys);
    }

    private function data(array $data)
    {
        return ['params' => json_encode($data)];
    }
}
