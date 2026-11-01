<?php

use App\Integrations\ServerManagers\AppDatabase\DatabaseInterface;

it('joins the organization slug and app name when they fit', function () {
    expect(DatabaseInterface::generateName('demo', 'nextcloud'))->toBe('demo_nextcloud');
});

it('keeps a name of exactly the maximum length as it is', function () {
    $name = DatabaseInterface::generateName(str_repeat('a', 20), str_repeat('b', 11));

    expect($name)->toBe(str_repeat('a', 20).'_'.str_repeat('b', 11));
    expect(strlen($name))->toBe(DatabaseInterface::MAX_NAME_LENGTH);
});

it('shortens a name over the maximum length to the maximum length', function () {
    $name = DatabaseInterface::generateName('a-rather-long-organization-slug', 'wordpress');

    expect(strlen($name))->toBe(DatabaseInterface::MAX_NAME_LENGTH);
    expect($name)->toStartWith('a-rather-long-organizat_');
});

it('gives long names that share a prefix different names', function () {
    $first = DatabaseInterface::generateName('a-rather-long-organization-slug', 'wordpress-one');
    $second = DatabaseInterface::generateName('a-rather-long-organization-slug', 'wordpress-two');

    expect($first)->not->toBe($second);
});

it('generates the same name every time for the same input', function () {
    expect(DatabaseInterface::generateName('a-rather-long-organization-slug', 'wordpress'))
        ->toBe(DatabaseInterface::generateName('a-rather-long-organization-slug', 'wordpress'));
});

it('quotes an identifier in backticks', function () {
    expect(DatabaseInterface::quoteIdentifier('demo_nextcloud'))->toBe('`demo_nextcloud`');
});

it('doubles backticks so an identifier cannot close its own quoting', function () {
    expect(DatabaseInterface::quoteIdentifier('x`; DROP DATABASE mysql; -- '))
        ->toBe('`x``; DROP DATABASE mysql; -- `');
});
