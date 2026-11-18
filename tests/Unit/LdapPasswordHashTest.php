<?php

use App\Ldap\Actions\PasswordHash;

it('hashes with salted SHA-512 crypt under the {CRYPT} scheme', function () {
    $hash = PasswordHash::make('s3cret!');

    expect($hash)->toStartWith('{CRYPT}$6$');
});

it('produces a hash that verifies with crypt()', function () {
    $stored = substr(PasswordHash::make('s3cret!'), strlen('{CRYPT}'));

    expect(hash_equals($stored, crypt('s3cret!', $stored)))->toBeTrue()
        ->and(hash_equals($stored, crypt('wrong', $stored)))->toBeFalse();
});

it('uses a new random salt every time', function () {
    expect(PasswordHash::make('same'))->not->toBe(PasswordHash::make('same'));
});

it('never falls back to MD5-crypt', function () {
    expect(PasswordHash::make('anything'))->not->toStartWith('{CRYPT}$1$');
});

it('defaults the LDAP port to 389 for both connections', function () {
    if (getenv('LDAP_PORT') !== false || isset($_ENV['LDAP_PORT']) || isset($_SERVER['LDAP_PORT'])) {
        $this->markTestSkipped('LDAP_PORT is set in this environment');
    }

    $config = require __DIR__.'/../../config/ldap.php';

    expect($config['connections']['default']['port'])->toBe(389)
        ->and($config['connections']['directory_reader']['port'])->toBe(389);
});
