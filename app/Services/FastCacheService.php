<?php

namespace App\Services;

use App\Support\Facades\Organization;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FastCacheService
{
    /**
     * Whether the configured cache store supports tagging, which this
     * service relies on to scope entries to a single organization. Checked
     * against the actual store capability (redis, memcached, ...) rather
     * than a hardcoded driver name, so it degrades safely instead of
     * silently no-caching when a non-taggable driver (e.g. "file") is
     * configured.
     */
    protected function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    public function retrieve(string $key, mixed $value, mixed $time = null): mixed
    {
        $org_name = Organization::account()?->slug;
        $taggable = $org_name && $this->supportsTags();

        if ($taggable && Cache::tags($org_name)->has($key)) {
            return Cache::tags($org_name)->get($key);
        }

        if ($value instanceof \Closure) {
            $value = $value();
        }

        if ($taggable) {
            Cache::tags($org_name)->put($key, $value, $time);
        }

        return $value;
    }

    public function clear(?string $key = null, ?\App\Organization $organization = null): void
    {
        if (! $this->supportsTags()) {
            // No global Cache::flush() fallback here: this method is called
            // per-organization on the hot path (subscription/plan updates,
            // gate checks), and a store-wide flush would wipe every other
            // tenant's cache too. Surface the misconfiguration instead.
            Log::warning('FastCacheService::clear() called with a non-taggable cache store; no cache was invalidated.', [
                'cache_driver' => config('cache.default'),
            ]);

            return;
        }

        $organization = $organization?->slug ?? Organization::account()?->slug;

        if (! $organization) {
            return;
        }

        if ($key) {
            Cache::tags([$organization])->pull($key);
        } else {
            Cache::tags([$organization])->flush();
        }
    }
}
