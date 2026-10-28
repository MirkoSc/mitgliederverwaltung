<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What the database says about a blob besides its bytes: the MIME type and
 * the name the file was uploaded under (docs/spec/03-datenhaltung.md
 * section 3). The stored file itself has a random name.
 */
final readonly class BlobMeta
{
    public function __construct(
        public string $mimeType = 'application/octet-stream',
        public string $originalName = '',
    ) {
    }

    /**
     * Only the file name, never a path: browsers (and attackers) send
     * "C:\Users\...\scan.pdf", and a path has no business in a column that
     * is later put into a Content-Disposition header.
     */
    public static function bereinigterName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return mb_substr(trim($name), 0, 255);
    }
}
