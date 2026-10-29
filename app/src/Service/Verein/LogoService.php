<?php

declare(strict_types=1);

namespace App\Service\Verein;

use App\Config\Paths;
use App\Dienste;
use App\Repository\SettingRepository;

/**
 * The club logo (docs/spec/11-vereinsdaten.md section 2): PNG or JPEG up to
 * 5 MB; GD derives the sizes once on upload, the original is not kept. The
 * files live in shared/var/logo/ (survive updates), the upload time is the
 * setting `logo_version` and serves as the ?v= cache buster.
 *
 * No SVG: GD cannot read it, and SVG can carry script.
 */
final readonly class LogoService
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** Rejects "decompression bombs": a small PNG can hold gigantic dimensions. */
    private const int MAX_PIXEL = 16_000_000;

    private const string EINSTELLUNG = 'logo_version';

    /**
     * Size name => [width, height, square canvas, background of that canvas
     * (null = transparent)]. A logo is scaled to fit and never distorted;
     * "kopf" and "anmeldung" keep its own proportions. The Apple touch icon
     * gets white behind it - iOS paints transparency black.
     */
    private const array GROESSEN = [
        'kopf' => [96, 96, false, null],
        'anmeldung' => [320, 160, false, null],
        'favicon' => [32, 32, true, null],
        'apple' => [180, 180, true, 0xFFFFFF],
    ];

    public function __construct(private Paths $paths, private SettingRepository $settings)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d->paths, $d->einstellungen()));
    }

    /** @return list<string> */
    public static function groessen(): array
    {
        return array_keys(self::GROESSEN);
    }

    /** The upload time of the current logo, null without one. */
    public function version(): ?int
    {
        $version = $this->settings->zahl(self::EINSTELLUNG);

        return $version > 0 ? $version : null;
    }

    /** Path of the derived image, null when there is no logo (or no such size). */
    public function datei(string $groesse): ?string
    {
        if (!isset(self::GROESSEN[$groesse]) || $this->version() === null) {
            return null;
        }
        $pfad = $this->paths->logoDir() . '/' . $groesse . '.png';

        return is_file($pfad) ? $pfad : null;
    }

    /**
     * Checks the uploaded file and replaces the logo.
     *
     * @throws LogoException
     */
    public function speichern(string $datei): void
    {
        if (!extension_loaded('gd')) {
            throw new LogoException('Auf diesem Server fehlt die Bildbibliothek (GD); das Logo kann nicht verarbeitet werden.');
        }
        $groesse = is_file($datei) ? filesize($datei) : false;
        if ($groesse === false || $groesse === 0) {
            throw new LogoException('Die Datei konnte nicht gelesen werden.');
        }
        if ($groesse > self::MAX_BYTES) {
            throw new LogoException('Die Datei ist größer als 5 MB.');
        }
        $info = @getimagesize($datei);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new LogoException('Bitte ein Bild im Format PNG oder JPEG hochladen (SVG und andere Formate gehen nicht).');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXEL) {
            throw new LogoException('Das Bild hat zu viele Bildpunkte (höchstens 16 Megapixel).');
        }

        $inhalt = file_get_contents($datei);
        $quelle = $inhalt === false ? false : @imagecreatefromstring($inhalt);
        if ($quelle === false) {
            throw new LogoException('Das Bild ist beschädigt und konnte nicht gelesen werden.');
        }

        $ordner = $this->paths->logoDir();
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            throw new LogoException('Der Ordner für das Logo kann nicht angelegt werden.');
        }

        // All derived images first, under temporary names, then renamed: a
        // failure half-way must not leave a logo of mixed old and new files.
        $zwischen = [];
        try {
            foreach (self::GROESSEN as $name => [$breite, $hoehe, $quadrat, $hintergrund]) {
                $png = $this->ableiten($quelle, $breite, $hoehe, $quadrat, $hintergrund);
                $temp = $ordner . '/' . $name . '.png.neu';
                if (file_put_contents($temp, $png) === false) {
                    throw new LogoException('Das Logo konnte nicht gespeichert werden.');
                }
                $zwischen[$name] = $temp;
            }
            foreach ($zwischen as $name => $temp) {
                rename($temp, $ordner . '/' . $name . '.png');
            }
        } finally {
            foreach ($zwischen as $temp) {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        }

        $this->settings->set(self::EINSTELLUNG, time());
    }

    public function entfernen(): void
    {
        $this->settings->delete(self::EINSTELLUNG);
        foreach (array_keys(self::GROESSEN) as $name) {
            $pfad = $this->paths->logoDir() . '/' . $name . '.png';
            if (is_file($pfad)) {
                unlink($pfad);
            }
        }
    }

    private function ableiten(\GdImage $quelle, int $breite, int $hoehe, bool $quadrat, ?int $hintergrund): string
    {
        $qb = imagesx($quelle);
        $qh = imagesy($quelle);
        $faktor = min($breite / $qb, $hoehe / $qh);
        $zb = max(1, (int) round($qb * $faktor));
        $zh = max(1, (int) round($qh * $faktor));
        // Square canvas for icons, the scaled size itself otherwise.
        $lb = $quadrat ? $breite : $zb;
        $lh = $quadrat ? $hoehe : $zh;

        $ziel = imagecreatetruecolor($lb, $lh);
        imagealphablending($ziel, false);
        imagesavealpha($ziel, true);
        imagefill($ziel, 0, 0, $hintergrund === null ? (int) imagecolorallocatealpha($ziel, 0, 0, 0, 127) : (int) imagecolorallocate($ziel, ($hintergrund >> 16) & 255, ($hintergrund >> 8) & 255, $hintergrund & 255));
        imagealphablending($ziel, true);
        imagecopyresampled($ziel, $quelle, intdiv($lb - $zb, 2), intdiv($lh - $zh, 2), 0, 0, $zb, $zh, $qb, $qh);
        imagealphablending($ziel, false);
        imagesavealpha($ziel, true);

        ob_start();
        imagepng($ziel);

        return (string) ob_get_clean();
    }
}
