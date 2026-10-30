<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Karte\GeoStatus;

/**
 * Table geo_anschrift (migration 191, issue #91): coordinates per address
 * hash. Holds no address text - App\Service\Karte\Anschrift::hash() is the
 * key.
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

    /**
     * @return array<string, array{status: GeoStatus, lat: ?float, lon: ?float}> by hash
     */
    public function alle(): array
    {
        $ergebnis = [];
        foreach ($this->pdo->query('SELECT anschrift_hash, status, lat, lon FROM geo_anschrift')->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
            $status = GeoStatus::tryFrom((string) $zeile['status']);
            if ($status === null) {
                continue;
            }
            $ergebnis[(string) $zeile['anschrift_hash']] = [
                'status' => $status,
                'lat' => $zeile['lat'] === null ? null : (float) $zeile['lat'],
                'lon' => $zeile['lon'] === null ? null : (float) $zeile['lon'],
            ];
        }

        return $ergebnis;
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
