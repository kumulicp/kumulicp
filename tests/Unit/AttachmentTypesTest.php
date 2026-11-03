<?php

use App\Services\Tickets\AttachmentTypes;

it('accepts a file only when the extension and the detected type agree', function (string $extension, string $mime, bool $expected) {
    expect(AttachmentTypes::accepts($extension, $mime))->toBe($expected);
})->with([
    'png' => ['png', 'image/png', true],
    'uppercase extension' => ['PNG', 'image/png', true],
    'jpeg' => ['jpg', 'image/jpeg', true],
    'pdf' => ['pdf', 'application/pdf', true],
    'log file' => ['log', 'text/plain', true],
    'docx detected as zip' => ['docx', 'application/zip', true],
    'a pdf called png' => ['png', 'application/pdf', false],
    'html called png' => ['png', 'text/html', false],
    'php called png' => ['png', 'text/x-php', false],
    'program called png' => ['png', 'application/x-dosexec', false],
    'a zip called pdf' => ['pdf', 'application/zip', false],
    'svg is never allowed' => ['svg', 'image/svg+xml', false],
    'html is never allowed' => ['html', 'text/html', false],
    'javascript is never allowed' => ['js', 'text/javascript', false],
    'no extension' => ['', 'text/plain', false],
]);

it('only treats raster images as safe to show in the page', function () {
    expect(AttachmentTypes::isRaster('image/png'))->toBeTrue()
        ->and(AttachmentTypes::isRaster('image/jpeg'))->toBeTrue()
        ->and(AttachmentTypes::isRaster('image/svg+xml'))->toBeFalse()
        ->and(AttachmentTypes::isRaster('application/pdf'))->toBeFalse();
});

it('never lists anything a browser would run', function () {
    expect(AttachmentTypes::extensions())->not->toContain('svg', 'html', 'htm', 'js', 'php', 'exe', 'sh', 'xml');
});
