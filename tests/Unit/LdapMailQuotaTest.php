<?php

use App\Integrations\ServerManagers\DockerMailServer\Interfaces\LdapEmailInterface;

it('writes a quota as gigabytes with a trailing G', function (string|int|float $setting, string $expected) {
    expect(LdapEmailInterface::quota($setting))->toBe($expected);
})->with([
    'integer' => [5, '5G'],
    'numeric string' => ['5', '5G'],
    'tens' => [10, '10G'],
    'hundreds' => [100, '100G'],
    'decimal' => ['2.5', '2.5G'],
    'trailing zero' => ['1.50', '1.5G'],
    'fraction' => [0.25, '0.25G'],
]);

it('leaves the quota out when the plan sets none', function (string|int|float|null $setting) {
    expect(LdapEmailInterface::quota($setting))->toBeNull();
})->with([
    'null' => [null],
    'blank' => [''],
    'zero' => [0],
    'zero string' => ['0'],
    'not a number' => ['unlimited'],
    'negative' => [-5],
]);
