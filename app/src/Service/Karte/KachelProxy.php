<?php

declare(strict_types=1);

namespace App\Service\Karte;

use App\Dienste;

/**
 * Map tiles of OpenStreetMap, fetched by the server and kept in a cache
 * under shared/var/kacheln/ (issue #91). The browser only ever asks this
 * installation (CSP 'self'); the tile server sees the installation, never a
 * visitor.
 *
 * Usage policy of the tile server: an identifying User-Agent, a local cache
 * that honours an expiry, no bulk downloads. Hence only the zoom levels a
 * club map needs, a cache of GUELTIG_TAGE days, and a size cap enforced by
 * the cleanup (App\Service\Cron\KachelAufraeumenTask).
 */
final readonly class KachelProxy
{
    public const string URL = 'https://tile.openstreetmap.org/%d/%d/%d.png';

    /** From a region (5) to single houses (18). */
    public const int ZOOM_MIN = 5;

    public const int ZOOM_MAX = 18;

    /** How long a cached tile is used before it is fetched again. */
    public const int GUELTIG_TAGE = 7;

    /** Upper bound of the cache; the cleanup trims it to 80 % of this. */
    public const int MAX_BYTES = 100 * 1024 * 1024;

    private const string PNG_SIGNATUR = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private string $verzeichnis,
        private HttpAbruf $http,
        private string $userAgent,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->paths->kachelDir(),
            KartenDienste::http($d),
            KartenDienste::userAgent($d),
        ));
    }

    /** Whether the tile exists in the zoom range the map offers. */
    public static function gueltig(int $z, int $x, int $y): bool
    {
        if ($z < self::ZOOM_MIN || $z > self::ZOOM_MAX) {
            return false;
        }
        $kanten = 1 << $z;

        return $x >= 0 && $y >= 0 && $x < $kanten && $y < $kanten;
    }

    /**
     * The PNG of one tile: from the cache while it is fresh, else from the
     * tile server. When the server fails, an expired copy still beats a
     * hole in the map. Null for an invalid tile or when there is nothing to
     * show.
     */
    public function kachel(int $z, int $x, int $y, \DateTimeImmutable $jetzt): ?string
    {
        if (!self::gueltig($z, $x, $y)) {
            return null;
        }

        $datei = $this->datei($z, $x, $y);
        $alter = is_file($datei) ? filemtime($datei) : false;
        if ($alter !== false && $alter > $jetzt->getTimestamp() - self::GUELTIG_TAGE * 86400) {
            $inhalt = file_get_contents($datei);
            if (is_string($inhalt) && $inhalt !== '') {
                return $inhalt;
            }
        }

        $antwort = $this->http->holen(sprintf(self::URL, $z, $x, $y), $this->userAgent);
        if ($antwort->ok() && str_starts_with($antwort->body, self::PNG_SIGNATUR)) {
            $this->ablegen($datei, $antwort->body, $jetzt);

            return $antwort->body;
        }

        $alt = $alter !== false ? file_get_contents($datei) : false;

        return is_string($alt) && $alt !== '' ? $alt : null;
    }

    /**
     * Removes expired tiles, then the oldest ones until the cache is below
     * 80 % of MAX_BYTES.
     *
     * @return int files removed
     */
    public function aufraeumen(\DateTimeImmutable $jetzt): int
    {
        if (!is_dir($this->verzeichnis)) {
            return 0;
        }

        $grenze = $jetzt->getTimestamp() - self::GUELTIG_TAGE * 86400;
        $entfernt = 0;
        $dateien = [];
        $summe = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->verzeichnis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $eintrag) {
            /** @var \SplFileInfo $eintrag */
            if ($eintrag->isDir()) {
                @rmdir($eintrag->getPathname()); // only succeeds when empty
                continue;
            }
            if ($eintrag->getMTime() <= $grenze) {
                if (@unlink($eintrag->getPathname())) {
                    $entfernt++;
                }
                continue;
            }
            $dateien[] = [$eintrag->getPathname(), $eintrag->getMTime(), $eintrag->getSize()];
            $summe += $eintrag->getSize();
        }

        if ($summe > self::MAX_BYTES) {
            usort($dateien, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
            foreach ($dateien as [$pfad, , $groesse]) {
                if ($summe <= (int) (self::MAX_BYTES * 0.8)) {
                    break;
                }
                if (@unlink($pfad)) {
                    $summe -= $groesse;
                    $entfernt++;
                }
            }
        }

        return $entfernt;
    }

    private function datei(int $z, int $x, int $y): string
    {
        return sprintf('%s/%d/%d/%d.png', $this->verzeichnis, $z, $x, $y);
    }

    /** Written beside and renamed: a reader never sees half a tile. Best effort. */
    private function ablegen(string $datei, string $inhalt, \DateTimeImmutable $jetzt): void
    {
        $ordner = dirname($datei);
        if (!is_dir($ordner) && !@mkdir($ordner, 0o775, true) && !is_dir($ordner)) {
            return;
        }
        $zwischen = $datei . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($zwischen, $inhalt) === false) {
            return;
        }
        @touch($zwischen, $jetzt->getTimestamp());
        if (!@rename($zwischen, $datei)) {
            @unlink($zwischen);
        }
    }
}
