<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Karte\GeoStatus;

/**
 * Table geo_anschrift (migration 191, issue #91): coordinates per address
 * hash - App\Service\Karte\Anschrift::hash() is the key. Holds no name
 * and, as a rule, no address text; the one exception is a search address
 * somebody gave by hand for the map (such_*, migration 193, issue #106).
 *
 * A row is "not asked yet" without a status: that only happens with a
 * search address that could not be asked right away.
 *
 * Also the lock of the geocoding (GET_LOCK, like App\Repository\CronSperre):
 * the cron and the admin's step chain must not both send requests at the
 * same time, or the one-per-second rule of the geocoder breaks.
 */
final class GeoAnschriftRepository
{
    private bool $gesperrt = false;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    private const string SPALTEN = 'anschrift_hash, status, lat, lon, such_strasse, such_plz, such_ort';

    /**
     * Every row; status null = not asked yet (a search address waits).
     *
     * @return array<string, array{status: ?GeoStatus, lat: ?float, lon: ?float, korrektur: ?array{strasse: string, plz: string, ort: string}}> by hash
     */
    public function alle(): array
    {
        $ergebnis = [];
        foreach ($this->pdo->query('SELECT ' . self::SPALTEN . ' FROM geo_anschrift')->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
            $ergebnis[(string) $zeile['anschrift_hash']] = self::eintragAus($zeile);
        }

        return $ergebnis;
    }

    /**
     * The row of one address, null when it was never asked (issue #106).
     *
     * @return array{status: ?GeoStatus, lat: ?float, lon: ?float, korrektur: ?array{strasse: string, plz: string, ort: string}}|null
     */
    public function eintrag(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SPALTEN . ' FROM geo_anschrift WHERE anschrift_hash = ?');
        $stmt->execute([$hash]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($zeile) ? self::eintragAus($zeile) : null;
    }

    /**
     * Remembers a search address for the map (issue #106) and forgets what
     * the geocoder said so far: the address counts as not asked until it is
     * asked with the search address.
     */
    public function korrekturSetzen(string $hash, string $strasse, string $plz, string $ort, \DateTimeImmutable $zeitpunkt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO geo_anschrift (anschrift_hash, status, lat, lon, geprueft_am, such_strasse, such_plz, such_ort)
             VALUES (?, NULL, NULL, NULL, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = NULL, lat = NULL, lon = NULL, geprueft_am = VALUES(geprueft_am),
                 such_strasse = VALUES(such_strasse), such_plz = VALUES(such_plz), such_ort = VALUES(such_ort)',
        );
        $stmt->execute([$hash, $zeitpunkt->format('Y-m-d H:i:s'), $strasse, $plz, $ort]);
    }

    /**
     * Forgets the search address and with it the result found with it:
     * the address is looked up with itself again (issue #106).
     */
    public function vergessen(string $hash): void
    {
        $this->pdo->prepare('DELETE FROM geo_anschrift WHERE anschrift_hash = ?')->execute([$hash]);
    }

    public function speichern(string $hash, GeoStatus $status, ?float $lat, ?float $lon, \DateTimeImmutable $zeitpunkt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO geo_anschrift (anschrift_hash, status, lat, lon, geprueft_am) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), lat = VALUES(lat), lon = VALUES(lon), geprueft_am = VALUES(geprueft_am)',
        );
        $stmt->execute([
            $hash,
            $status->value,
            $lat === null ? null : number_format($lat, 6, '.', ''),
            $lon === null ? null : number_format($lon, 6, '.', ''),
            $zeitpunkt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Removes every row whose hash is not in the list - addresses no member
     * has any more (moved, left, deleted).
     *
     * @param list<string> $behalten
     * @return int rows removed
     */
    public function entfernenAusser(array $behalten): int
    {
        $behalten = array_flip($behalten);
        $weg = array_values(array_filter(
            array_keys($this->alle()),
            static fn(string $hash): bool => !isset($behalten[$hash]),
        ));
        if ($weg === []) {
            return 0;
        }

        $anzahl = 0;
        foreach (array_chunk($weg, 200) as $teil) {
            $stmt = $this->pdo->prepare('DELETE FROM geo_anschrift WHERE anschrift_hash IN (' . implode(',', array_fill(0, count($teil), '?')) . ')');
            $stmt->execute($teil);
            $anzahl += $stmt->rowCount();
        }

        return $anzahl;
    }

    /**
     * @param array<string, mixed> $zeile
     * @return array{status: ?GeoStatus, lat: ?float, lon: ?float, korrektur: ?array{strasse: string, plz: string, ort: string}}
     */
    private static function eintragAus(array $zeile): array
    {
        $korrektur = null;
        if ($zeile['such_strasse'] !== null || $zeile['such_plz'] !== null || $zeile['such_ort'] !== null) {
            $korrektur = [
                'strasse' => (string) $zeile['such_strasse'],
                'plz' => (string) $zeile['such_plz'],
                'ort' => (string) $zeile['such_ort'],
            ];
        }

        return [
            'status' => GeoStatus::tryFrom((string) $zeile['status']),
            'lat' => $zeile['lat'] === null ? null : (float) $zeile['lat'],
            'lon' => $zeile['lon'] === null ? null : (float) $zeile['lon'],
            'korrektur' => $korrektur,
        ];
    }

    /** Takes the geocoding lock without waiting; false when another request holds it. */
    public function sperren(): bool
    {
        $stmt = $this->pdo->prepare("SELECT GET_LOCK(CONCAT('mv_geo_', DATABASE()), 0)");
        $stmt->execute();

        return $this->gesperrt = ((int) $stmt->fetchColumn()) === 1;
    }

    public function freigeben(): void
    {
        if (!$this->gesperrt) {
            return;
        }
        $this->pdo->query("SELECT RELEASE_LOCK(CONCAT('mv_geo_', DATABASE()))")->fetchAll();
        $this->gesperrt = false;
    }
}
