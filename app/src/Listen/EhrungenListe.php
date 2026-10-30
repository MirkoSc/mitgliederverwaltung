<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Repository\EhrungRepository;
use App\Repository\SettingRepository;
use App\Repository\Zeilen;
use App\Support\Datum;

/**
 * Members to honour in a year (docs/spec/09-listen-und-exporte.md section
 * 2): a member is listed in year J when `J - year of joining` is one of the
 * honour levels (setting `ehrungsstufen`). Breaks do not count; a member
 * without a known joining date is not listed (a count is shown instead).
 * "geehrt" is noted per member and level and can be taken back.
 */
final readonly class EhrungenListe implements Liste
{
    public const string PFAD_VERMERK = '/app/listen/ehrungen/vermerk';

    /**
     * The database is reached lazily (see GeburtstageListe).
     *
     * @param \Closure(): \PDO $pdo
     * @param \Closure(): SettingRepository $einstellungen
     * @param \Closure(): EhrungRepository $ehrungen
     * @param \Closure(): \DateTimeImmutable $heute
     */
    public function __construct(
        private \Closure $pdo,
        private \Closure $einstellungen,
        private \Closure $ehrungen,
        private \Closure $heute,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(
            $dienste->pdo(...),
            $dienste->einstellungen(...),
            static fn(): EhrungRepository => new EhrungRepository($dienste->pdo()),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE)),
        );
    }

    public function schluessel(): string
    {
        return 'ehrungen';
    }

    public function titel(): string
    {
        return 'Ehrungen';
    }

    public function beschreibung(): string
    {
        return 'Wer in diesem Jahr eine Ehrungsstufe der Mitgliedschaft erreicht, mit Vermerk „geehrt“.';
    }

    public function reihenfolge(): int
    {
        return 20;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function filter(): array
    {
        return [
            ListenFilter::jahr('jahr', 'Jahr', (int) ($this->heute)()->format('Y')),
            ListenFilter::haken('nur_offene', 'Nur offene'),
        ];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $jahr = (int) ($filter['jahr'] ?? ($this->heute)()->format('Y'));
        $nurOffene = (bool) ($filter['nur_offene'] ?? false);
        $stufen = $this->stufen();
        $darfVermerken = $konto->darf(Permission::MitgliederBearbeiten);

        $geehrt = [];
        foreach ($stufen as $stufe) {
            $geehrt[$stufe] = ($this->ehrungen)()->derStufe($stufe);
        }

        $zeilen = [];
        foreach ($this->mitglieder($jahr, $stufen) as $m) {
            $jahre = $jahr - (int) substr($m['eintritt'], 0, 4);
            $vermerk = $geehrt[$jahre][$m['id']] ?? null;
            if ($nurOffene && $vermerk !== null) {
                continue;
            }
            $felder = ['mitglied' => (string) $m['id'], 'stufe' => (string) $jahre, 'jahr' => (string) $jahr];
            if ($nurOffene) {
                $felder['nur_offene'] = '1';
            }
            $zeilen[] = new ListenZeile(
                [
                    'name' => $m['vorname'] === '' ? $m['nachname'] : $m['nachname'] . ', ' . $m['vorname'],
                    'eintritt' => new \DateTimeImmutable($m['eintritt']),
                    'jahre' => $jahre,
                    'stufe' => $jahre . ' Jahre',
                    'abteilungen' => implode(', ', $m['abteilungen']),
                    'geehrt_am' => $vermerk?->geehrtAm,
                ],
                $m['id'],
                null,
                match (true) {
                    // "Nur lesen" sees the list without the buttons (issue #87).
                    !$darfVermerken => null,
                    $vermerk === null => new ListenAktion('Geehrt', self::PFAD_VERMERK, $felder + ['aktion' => 'geehrt']),
                    default => new ListenAktion('Zurücknehmen', self::PFAD_VERMERK, $felder + ['aktion' => 'zurueck'], true),
                },
            );
        }

        $hinweise = [];
        $ohne = $this->ohneEintritt($jahr);
        if ($ohne > 0) {
            $hinweise[] = sprintf(
                '%s ohne Eintrittsdatum %s nicht in der Liste. Bitte das Eintrittsdatum nachtragen.',
                $ohne === 1 ? '1 Mitglied' : $ohne . ' Mitglieder',
                $ohne === 1 ? 'steht' : 'stehen',
            );
        }

        return new ListenErgebnis(
            [
                Spalte::text('name', 'Name'),
                Spalte::datum('eintritt', 'Eintritt'),
                Spalte::zahl('jahre', 'Jahre'),
                Spalte::text('stufe', 'Stufe'),
                Spalte::text('abteilungen', 'Abteilungen', true),
                Spalte::datum('geehrt_am', 'Geehrt am'),
            ],
            $zeilen,
            $hinweise,
        );
    }

    public function dateiname(array $filter): string
    {
        return 'ehrungen-' . (int) ($filter['jahr'] ?? ($this->heute)()->format('Y')) . (!empty($filter['nur_offene']) ? '-offene' : '');
    }

    /** @return list<int> */
    private function stufen(): array
    {
        $stufen = array_filter((array) ($this->einstellungen)()->get('ehrungsstufen', []), static fn(mixed $s): bool => is_int($s) && $s > 0);

        return array_values(array_unique($stufen));
    }

    /**
     * Members who joined `stufe` years before the year and have not left
     * before it began (they may have left during it).
     *
     * @param list<int> $stufen
     * @return list<array{id: int, nachname: string, vorname: string, eintritt: string, abteilungen: list<string>}>
     */
    private function mitglieder(int $jahr, array $stufen): array
    {
        if ($stufen === []) {
            return [];
        }
        $eintrittsjahre = array_map(static fn(int $s): int => $jahr - $s, $stufen);
        $stmt = ($this->pdo)()->prepare(sprintf(
            'SELECT m.id, m.nachname, m.vorname, m.eintrittsdatum FROM mitglied m
             WHERE m.eintrittsdatum IS NOT NULL AND YEAR(m.eintrittsdatum) IN (%s)
               AND (m.austrittsdatum IS NULL OR m.austrittsdatum >= ?)
             ORDER BY m.nachname, m.vorname, m.mitgliedsnummer',
            Zeilen::platzhalter(count($eintrittsjahre)),
        ));
        $stmt->execute([...$eintrittsjahre, sprintf('%04d-01-01', $jahr)]);
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
            'eintritt' => (string) $z['eintrittsdatum'],
            'abteilungen' => $namen[(int) $z['id']] ?? [],
        ], $zeilen);
    }

    /** Members not yet left whose joining date is unknown. */
    private function ohneEintritt(int $jahr): int
    {
        $stmt = ($this->pdo)()->prepare(
            'SELECT COUNT(*) FROM mitglied WHERE eintrittsdatum IS NULL AND (austrittsdatum IS NULL OR austrittsdatum >= ?)',
        );
        $stmt->execute([sprintf('%04d-01-01', $jahr)]);

        return (int) $stmt->fetchColumn();
    }
}
