<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * A ZIP file in memory. ZipArchive only writes to a file, and an export is
 * never stored on the webspace, not even for the length of a request
 * (CLAUDE.md section 4) - xlsx is a ZIP, so the few files it needs are
 * packed here: deflate, no encryption, no ZIP64 (an export of a club is
 * a few hundred kilobytes).
 */
final class ZipWriter
{
    /** 1 January 1980, 00:00 - fixed, so the same content gives the same bytes. */
    private const int DOS_DATUM = 0x0021;

    /** @var list<array{name: string, crc: int, komprimiert: string, groesse: int, versatz: int}> */
    private array $eintraege = [];

    private string $daten = '';

    public function datei(string $name, string $inhalt): void
    {
        if ($name === '' || strlen($name) > 0xFFFF) {
            throw new \InvalidArgumentException('Invalid file name in ZIP.');
        }
        $komprimiert = gzdeflate($inhalt, 6);
        if ($komprimiert === false) {
            throw new \RuntimeException('Could not compress a file of the ZIP.');
        }
        $crc = crc32($inhalt);
        $groesse = strlen($inhalt);
        $versatz = strlen($this->daten);

        // local file header
        $this->daten .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 8, 0, self::DOS_DATUM, $crc, strlen($komprimiert), $groesse, strlen($name), 0)
            . $name . $komprimiert;
        $this->eintraege[] = ['name' => $name, 'crc' => $crc, 'komprimiert' => $komprimiert, 'groesse' => $groesse, 'versatz' => $versatz];
    }

    public function inhalt(): string
    {
        $verzeichnis = '';
        foreach ($this->eintraege as $e) {
            // central directory header
            $verzeichnis .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                0x0800,
                8,
                0,
                self::DOS_DATUM,
                $e['crc'],
                strlen($e['komprimiert']),
                $e['groesse'],
                strlen($e['name']),
                0,
                0,
                0,
                0,
                0,
                $e['versatz'],
            ) . $e['name'];
        }

        return $this->daten . $verzeichnis
            . pack('VvvvvVVv', 0x06054B50, 0, 0, count($this->eintraege), count($this->eintraege), strlen($verzeichnis), strlen($this->daten), 0);
    }
}
