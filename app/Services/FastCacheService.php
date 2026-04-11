<?php

namespace App\Services;

use App\Support\Facades\Organization;
use Illuminate\Support\Facades\Cache;

class FastCacheService
{
    public function retrieve(string $key, mixed $value, mixed $time = null): mixed
    {
        $org_name = Organization::account()?->slug;

        if ($org_name && config('cache.default') === 'redis') {
            if (Cache::tags($org_name)->has($key)) {
                return Cache::tags($org_name)->get($key);
            }
        }

        if ($value instanceof \Closure) {
            $value = $value();
        }

        if ($org_name && config('cache.default') === 'redis') {
            Cache::tags($org_name)->put($key, $value, $time);
        }

        return $value;
    }

    public function clear(?string $key = null, ?\App\Organization $organization = null): void
    {
        if (config('cache.default') === 'redis') {
            $organization = $organization?->slug ?? Organization::account()->slug;
            if ($key) {
                Cache::tags([$organization])->pull($key);
            } else {
                Cache::tags([$organization])->flush();
            }
        } else {
            Cache::flush();
        }
    }
}
