<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

class Locations extends Controller
{
    public function countries()
    {
        return Cache::remember('locations.countries', now()->addDay(), fn () => Country::orderBy('name')
            ->get(['iso2', 'name'])
            ->map(fn ($country) => ['value' => $country->iso2, 'text' => $country->name])
            ->all());
    }

    public function states(string $country)
    {
        $country = strtoupper($country);

        return Cache::remember("locations.states.{$country}", now()->addDay(), fn () => State::where('country_code', $country)
            ->whereNotNull('state_code')
            ->orderBy('name')
            ->get(['state_code', 'name'])
            ->map(fn ($state) => ['value' => $state->state_code, 'text' => $state->name])
            ->all());
    }
}
