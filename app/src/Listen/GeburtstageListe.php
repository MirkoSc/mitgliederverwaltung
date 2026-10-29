<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Repository\SettingRepository;
use App\Repository\Zeilen;
use App\Support\Datum;

/**
 * Birthdays of a week, month or year, round ones marked
 * (docs/spec/09-listen-und-exporte.md section 2). Former members are left
 * out; the round ages are the setting `runde_geburtstage`.
 */
final readonly class GeburtstageListe implements Liste
{
    /**
     * The database is reached lazily: the page "Listen" builds every list
     * to show the selection, also where no database is needed yet.
     *
     * @param \Closure(): \PDO $pdo
     * @param \Closure(): SettingRepository $einstellungen
     * @param \Closure(): \DateTimeImmutable $heute
     */
    public function __construct(
        private \Closure $pdo,
        private \Closure $einstellungen,
        private \Closure $heute,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self($dienste->pdo(...), $dienste->einstellungen(...), static fn(): \DateTimeImmutable => new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE)));
    }

    public function schluessel(): string
    {
        return 'geburtstage';
    }

    public function titel(): string
    {
        return 'Geburtstage';
    }

    public function beschreibung(): string
    {
        return 'Wer in der Woche, im Monat oder im Jahr Geburtstag hat, runde Geburtstage markiert.';
    }

    public function reihenfolge(): int
    {
        return 10;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function filter(): array
    {
        return [
            ListenFilter::auswahl('zeitraum', 'Zeitraum', GeburtstageZeitraum::ARTEN, 'monat'),
            ListenFilter::datum('datum', 'Ab Tag (leer = heute)'),
            ListenFilter::haken('nur_runde', 'Nur runde Geburtstage'),
        ];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $heute = ($this->heute)();
        $anker = Datum::ausEingabe((string) ($filter['datum'] ?? '')) ?? $heute;
        $zeitraum = GeburtstageZeitraum::um((string) ($filter['zeitraum'] ?? 'monat'), $anker);
        $runde = array_values(array_filter((array) ($this->einstellungen)()->get('runde_geburtstage', []), 'is_int'));
        $nurRunde = (bool) ($filter['nur_runde'] ?? false);

        $zeilen = [];
        foreach ($this->mitglieder($zeitraum, $heute) as $m) {
            $geburtsdatum = new \DateTimeImmutable((string) $m['geburtsdatum']);
            $tag = $zeitraum->geburtstagIn($geburtsdatum);
            if ($tag === null) {
                continue;
            }
            $alter = (int) $tag->format('Y') - (int) $geburtsdatum->format('Y');
            $istRund = in_array($alter, $runde, true);
            if ($nurRunde && !$istRund) {
                continue;
            }
            $zeilen[] = [
                'tag' => $tag,
                'zeile' => new ListenZeile(
                    [
                        'name' => $m['vorname'] === '' ? $m['nachname'] : $m['nachname'] . ', ' . $m['vorname'],
                        'geburtstag' => $tag,
                        'alter' => $alter,
                        'abteilungen' => implode(', ', $m['abteilungen']),
                    ],
                    (int) $m['id'],
                    $istRund ? 'rund' : null,
                ),
            ];
        }
        usort($zeilen, static fn(array $a, array $b): int => $a['tag'] <=> $b['tag']); // stable: SQL order of names within a day

        return new ListenErgebnis(
            [
                Spalte::text('name', 'Name'),
                Spalte::datum('geburtstag', 'Geburtstag'),
                Spalte::zahl('alter', 'Neues Alter'),
                Spalte::text('abteilungen', 'Abteilungen', true),
            ],
            array_column($zeilen, 'zeile'),
            [],
            new ListenNavigation(
                $zeitraum->titel(),
                ['datum' => Datum::fuerDatenbank($zeitraum->nachbar(-1)->von)],
                ['datum' => Datum::fuerDatenbank($zeitraum->nachbar(1)->von)],
            ),
        );
    }

    public function dateiname(array $filter): string
    {
        $anker = Datum::ausEingabe((string) ($filter['datum'] ?? '')) ?? ($this->heute)();
        $zeitraum = GeburtstageZeitraum::um((string) ($filter['zeitraum'] ?? 'monat'), $anker);

        return 'geburtstage-' . match ($zeitraum->art) {
            'woche' => $zeitraum->von->format('Y-m-d') . '-woche',
            'jahr' => $zeitraum->von->format('Y'),
            default => $zeitraum->von->format('Y-m'),
        };
    }

    /**
     * Members with a birth date in the months of the period, not yet left;
     * the exact day is decided in PHP (29 February, New Year).
     *
     * @return list<array{id: int, nachname: string, vorname: string, geburtsdatum: string, abteilungen: list<string>}>
     */
    private function mitglieder(GeburtstageZeitraum $zeitraum, \DateTimeImmutable $heute): array
    {
        $monate = $zeitraum->monate();
        $stmt = ($this->pdo)()->prepare(sprintf(
            'SELECT m.id, m.nachname, m.vorname, m.geburtsdatum FROM mitglied m
             WHERE m.geburtsdatum IS NOT NULL AND MONTH(m.geburtsdatum) IN (%s)
               AND (m.austrittsdatum IS NULL OR m.austrittsdatum > ?)
             ORDER BY m.nachname, m.vorname, m.mitgliedsnummer',
            Zeilen::platzhalter(count($monate)),
        ));
        $stmt->execute([...$monate, Zeilen::fuerDatum($heute)]);
        $zeilen = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($zeilen === []) {
            return [];
        }

        $ids = array_map(static fn(array $z): int => (int) $z['id'], $zeilen);
        $abt = ($this->pdo)()->prepare(sprintf(
            'SELECT ma.mitglied_id, a.name FROM mitglied_abteilung ma JOIN abteilung a ON a.id = ma.abteilung_id
             WHERE ma.mitglied_id IN (%s) ORDER BY a.sortierung, a.name, a.id',
            Zeilen::platzhalter(count($ids)),
        ));
        $abt->execute($ids);
        $namen = [];
        foreach ($abt->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $namen[(int) $z['mitglied_id']][] = (string) $z['name'];
        }

        return array_map(static fn(array $z): array => [
            'id' => (int) $z['id'],
            'nachname' => (string) $z['nachname'],
            'vorname' => (string) $z['vorname'],
            'geburtsdatum' => (string) $z['geburtsdatum'],
            'abteilungen' => $namen[(int) $z['id']] ?? [],
        ], $zeilen);
    }
}
