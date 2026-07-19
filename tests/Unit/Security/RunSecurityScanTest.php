<?php

use App\Actions\Security\RunSecurityScan;

function callTruncateRawOutput(string $raw_output): string
{
    $method = new ReflectionMethod(RunSecurityScan::class, 'truncateRawOutput');
    $method->setAccessible(true);

    return $method->invoke(null, $raw_output);
}

test('raw output under the limit is stored unchanged', function () {
    $raw = str_repeat('a', 1000);

    expect(callTruncateRawOutput($raw))->toBe($raw);
});

test('raw output over the limit is truncated with a notice', function () {
    $raw = str_repeat('a', 600 * 1024);

    $result = callTruncateRawOutput($raw);

    expect(strlen($result))->toBeLessThan(strlen($raw))
        ->and($result)->toContain('[... output truncated, 614,400 bytes total]');
});