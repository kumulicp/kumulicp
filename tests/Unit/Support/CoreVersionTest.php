<?php

use App\Support\CoreVersion;

it('reads the core version from the VERSION file', function () {
    $file = trim(file_get_contents(base_path('VERSION')));

    expect($file)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(config('app.version'))->toBe($file)
        ->and(CoreVersion::current())->toBe($file);
});

it('is currently at 0.5.0', function () {
    expect(CoreVersion::current())->toBe('0.5.0')
        ->and(CoreVersion::major())->toBe(0)
        ->and(CoreVersion::minor())->toBe(5)
        ->and(CoreVersion::patch())->toBe(0);
});

it('checks composer style constraints', function () {
    config(['app.version' => '0.5.2']);

    expect(CoreVersion::satisfies('^0.5'))->toBeTrue()
        ->and(CoreVersion::satisfies('0.5.*'))->toBeTrue()
        ->and(CoreVersion::satisfies('>=0.5.1 <0.7'))->toBeTrue()
        ->and(CoreVersion::satisfies('^0.6'))->toBeFalse()
        ->and(CoreVersion::satisfies('>=1.0'))->toBeFalse();
});

it('compares against other versions', function () {
    config(['app.version' => '0.5.2']);

    expect(CoreVersion::atLeast('0.5.2'))->toBeTrue()
        ->and(CoreVersion::atLeast('v0.5.0'))->toBeTrue()
        ->and(CoreVersion::atLeast('0.6.0'))->toBeFalse()
        ->and(CoreVersion::lessThan('0.6.0'))->toBeTrue()
        ->and(CoreVersion::lessThan('0.5.2'))->toBeFalse()
        ->and(CoreVersion::compare('0.5.2'))->toBe(0)
        ->and(CoreVersion::compare('0.4.9'))->toBe(1)
        ->and(CoreVersion::compare('0.10.0'))->toBe(-1);
});

it('ignores a leading v and whitespace in the version', function () {
    config(['app.version' => " v1.2.3\n"]);

    expect(CoreVersion::current())->toBe('1.2.3');
});

it('fails loudly when no version is configured', function () {
    config(['app.version' => '']);

    CoreVersion::current();
})->throws(InvalidArgumentException::class);
