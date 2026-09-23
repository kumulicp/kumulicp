<?php

use App\Ldap\Actions\Dn;

it('escapes a comma in a cn so it is not parsed as a separate rdn', function () {
    $dn = Dn::create('demo', 'users', 'Smith, John');

    expect($dn)->not->toContain('cn=Smith,')
        ->and(Dn::split($dn)['cn'][0])->toBe('Smith, John');
});

it('escapes a plus sign in a cn so it is not parsed as a multi-valued rdn', function () {
    $dn = Dn::create('demo', 'users', 'Team+A');

    expect(Dn::split($dn)['cn'][0])->toBe('Team+A');
});

it('escapes an equals sign in a cn', function () {
    $dn = Dn::create('demo', 'users', 'key=value');

    expect(Dn::split($dn)['cn'][0])->toBe('key=value');
});

it('escapes leading and trailing spaces in a cn', function () {
    $dn = Dn::create('demo', 'users', '  Spaced Name  ');

    expect(Dn::split($dn)['cn'][0])->toBe('  Spaced Name  ');
});

it('round-trips a comma-containing value through multiple cn levels', function () {
    $dn = Dn::create('demo', 'applications', ['nextcloud', 'role, special']);

    $split = Dn::split($dn);
    expect($split['cn'][0])->toBe('role, special')
        ->and($split['cn'][1])->toBe('nextcloud');
});

it('escapes special characters in the organization slug', function () {
    $dn = Dn::create('demo, inc', 'users', 'jdoe');

    expect(Dn::split($dn)['o'][0])->toBe('demo, inc');
});

it('does not mis-parse an ou containing a comma when splitting', function () {
    $dn = Dn::create('demo', 'first, second', 'jdoe');

    expect(Dn::split($dn)['ou'][0])->toBe('first, second');
});
