<?php

namespace App\Services\Tickets;

use finfo;

/**
 * What may be attached. A file is accepted only when its extension is on the
 * list AND the type detected from its contents is one that extension may have,
 * so a renamed executable or an HTML page called "logo.png" is turned away.
 * Anything that browsers will run or render (html, svg, js, exe...) is simply
 * not on the list.
 */
class AttachmentTypes
{
    private const OOXML = [
        'application/zip',
        'application/x-zip-compressed',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        // Images
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        // Documents
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::OOXML],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::OOXML],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::OOXML],
        'odt' => ['application/vnd.oasis.opendocument.text', ...self::OOXML],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', ...self::OOXML],
        // Text
        'txt' => ['text/plain'],
        'log' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'yaml' => ['text/plain', 'text/x-yaml', 'application/x-yaml'],
        'yml' => ['text/plain', 'text/x-yaml', 'application/x-yaml'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'json' => ['application/json', 'text/plain'],
        // Archives
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    private const RASTER = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(self::ALLOWED);
    }

    public static function accepts(string $extension, string $detectedMime): bool
    {
        $allowed = self::ALLOWED[strtolower($extension)] ?? null;

        return $allowed !== null && in_array($detectedMime, $allowed, true);
    }

    /**
     * Images safe to show inline in a page. SVG is deliberately not one.
     */
    public static function isRaster(string $mime): bool
    {
        return in_array($mime, self::RASTER, true);
    }

    /**
     * The type of the file's actual contents.
     */
    public function detect(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
