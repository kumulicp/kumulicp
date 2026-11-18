<?php

use App\Support\Email\AddressDomains;

it('finds an address at exactly the domain', function () {
    expect(AddressDomains::hasAddressAt(['jane@example.com'], 'example.com'))->toBeTrue();
});

it('does not match a domain that only ends the same way', function () {
    expect(AddressDomains::hasAddressAt(['jane@myexample.com'], 'example.com'))->toBeFalse();
});

it('does not match a longer domain that starts the same way', function () {
    expect(AddressDomains::hasAddressAt(['jane@example.com.au'], 'example.com'))->toBeFalse();
});

it('does not match when the domain only appears before the @', function () {
    expect(AddressDomains::hasAddressAt(['example.com@other.org'], 'example.com'))->toBeFalse();
});

it('ignores case on both sides', function () {
    expect(AddressDomains::hasAddressAt(['Jane@EXAMPLE.com'], 'Example.COM'))->toBeTrue();
});

it('finds the domain among several addresses', function () {
    expect(AddressDomains::hasAddressAt(['a@one.com', 'b@two.com'], 'two.com'))->toBeTrue()
        ->and(AddressDomains::hasAddressAt(['a@one.com', 'b@two.com'], 'three.com'))->toBeFalse();
});

it('finds nothing in an empty list or in values that are not addresses', function () {
    expect(AddressDomains::hasAddressAt([], 'example.com'))->toBeFalse()
        ->and(AddressDomains::hasAddressAt([null, 5], 'example.com'))->toBeFalse();
});
