<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Small images made with GD, written to a temporary file. */
final class TestBilder
{
    public static function png(string $verzeichnis, int $breite = 400, int $hoehe = 200): string
    {
        $bild = imagecreatetruecolor($breite, $hoehe);
        imagefilledrectangle($bild, 0, 0, $breite, $hoehe, (int) imagecolorallocate($bild, 200, 30, 30));
        $datei = $verzeichnis . '/logo-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($bild, $datei);

        return $datei;
    }

    public static function jpeg(string $verzeichnis, int $breite = 300, int $hoehe = 300): string
    {
        $bild = imagecreatetruecolor($breite, $hoehe);
        imagefilledrectangle($bild, 0, 0, $breite, $hoehe, (int) imagecolorallocate($bild, 30, 30, 200));
        $datei = $verzeichnis . '/logo-' . bin2hex(random_bytes(3)) . '.jpg';
        imagejpeg($bild, $datei);

        return $datei;
    }

    public static function svg(string $verzeichnis): string
    {
        $datei = $verzeichnis . '/logo.svg';
        file_put_contents($datei, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');

        return $datei;
    }

    /** Removes a temporary directory with everything in it. */
    public static function loeschen(string $verzeichnis): void
    {
        if (!is_dir($verzeichnis)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($verzeichnis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $eintrag) {
            $eintrag->isDir() && !$eintrag->isLink() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
        }
        rmdir($verzeichnis);
    }
}
