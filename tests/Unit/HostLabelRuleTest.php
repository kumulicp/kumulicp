<?php

use App\Rules\HostLabel;

it('keeps app subdomains to a single plain label', function (string $host, bool $valid) {
    expect((new HostLabel)->passes('host', $host))->toBe($valid);
})->with([
    ['myapp', true],
    ['@', true],
    ['*', true],
    ['mta._domainkey', false],
    ['_dmarc', false],
    ['a.b', false],
]);

it('allows dotted and underscore hosts for DNS records', function (string $host, bool $valid) {
    expect((new HostLabel(true))->passes('host', $host))->toBe($valid);
})->with([
    ['mta._domainkey', true],
    ['_dmarc', true],
    ['_acme-challenge.sub', true],
    ['*.sub', true],
    ['@', true],
    ['www', true],
    ['-bad', false],
    ['bad-', false],
    ['a..b', false],
    ['has space', false],
    ['UPPER', false],
    ['', false],
]);
