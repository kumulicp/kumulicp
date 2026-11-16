<?php

use App\Support\SingleOrganizationTerms;

/**
 * 'a.b' => 'text' for every string in a nested array.
 */
function termsFlatten(array $strings, string $prefix = ''): array
{
    $flat = [];

    foreach ($strings as $key => $value) {
        is_array($value)
            ? $flat += termsFlatten($value, "{$prefix}{$key}.")
            : $flat["{$prefix}{$key}"] = $value;
    }

    return $flat;
}

dataset('term_languages', ['en', 'es']);

it('only replaces strings that exist and keeps what is filled into them', function (string $language) {
    $lines = SingleOrganizationTerms::lines($language);

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $key => $profile) {
        $plan = app('translator')->get($key, [], $language, false);

        expect($plan)->not->toBe($key, "{$key} has no string to replace in {$language}");
        expect($profile)->not->toBe($plan, "{$key} is the same in {$language}");

        preg_match_all('/:\w+/', $plan, $plan_placeholders);
        preg_match_all('/:\w+/', $profile, $profile_placeholders);
        expect($profile_placeholders[0])->toBe($plan_placeholders[0], "{$key} lost a placeholder in {$language}");
    }
})->with('term_languages');

it('only replaces Vue strings that exist and keeps what is filled into them', function (string $language) {
    $normal = termsFlatten(json_decode(file_get_contents(base_path("resources/js/i18n/locales/{$language}.json")), true));
    $overrides = termsFlatten(json_decode(file_get_contents(base_path("resources/js/i18n/single/{$language}.json")), true));

    expect($overrides)->not->toBeEmpty();

    foreach ($overrides as $key => $profile) {
        expect($normal)->toHaveKey($key);
        expect($profile)->not->toBe($normal[$key], "{$key} is the same in {$language}");

        preg_match_all('/\{\w+\}/', $normal[$key], $plan_placeholders);
        preg_match_all('/\{\w+\}/', $profile, $profile_placeholders);
        expect($profile_placeholders[0])->toBe($plan_placeholders[0], "{$key} lost a placeholder in {$language}");
    }
})->with('term_languages');

it('calls an app plan a profile once applied, and leaves the rest of the strings alone', function () {
    expect(__('organization.readiness.plan_missing.action'))->toBe('Add a plan');

    SingleOrganizationTerms::apply('en');

    expect(__('organization.readiness.plan_missing.action'))->toBe('Add a profile');
    expect(__('admin.applications.plans.first_plan_name'))->toBe('First Profile');
    // The rest of the same groups is still there
    expect(__('organization.readiness.servers.action'))->toBe('Add a server');
    expect(__('admin.applications.apps'))->not->toBe('admin.applications.apps');
});
