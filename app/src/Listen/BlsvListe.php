<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Repository\AbteilungRepository;
use App\Repository\MitgliedRepository;
use App\Service\Export\BlsvFormat;
use App\Service\Verein\VereinRepository;
use App\Support\Datum;

/**
 * "BLSV-Meldung" (docs/spec/09-listen-und-exporte.md section 4): the member
 * import of the BLSV template, columns and order exactly as the template.
 * Open points of the format are constants in App\Service\Export\BlsvFormat.
 */
final class BlsvListe implements Liste
{
    /** The database is only touched when the list is shown (the selection page needs none). */
    public function __construct(private readonly Dienste $dienste)
    {
    }

    public static function erzeugen(Dienste $dienste): static
    {
        return new self($dienste);
    }

    public function schluessel(): string
    {
        return 'blsv';
    }

    public function titel(): string
    {
        return 'BLSV-Meldung';
    }

    public function beschreibung(): string
    {
        return 'Mitglieder im Format der BLSV-Vorlage „Mitgliederimport", eine Zeile je Mitglied und Abteilung.';
    }

    public function reihenfolge(): int
    {
        return 60;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function filter(): array
    {
        return [ListenFilter::datum('stichtag', 'Stichtag')];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $stichtag = self::stichtag($filter);
        $tag = $stichtag->format('Y-m-d');
        $pdo = $this->dienste->pdo();

        $spalten = [];
        foreach (BlsvFormat::SPALTEN as $schluessel => $titel) {
            $spalten[] = match ($schluessel) {
                'geburtsdatum' => Spalte::datum($schluessel, $titel),
                default => Spalte::text($schluessel, $titel),
            };
        }

        $sparten = [];
        foreach (new AbteilungRepository($pdo)->alle() as $abteilung) {
            $sparten[(int) $abteilung->id] = $abteilung->blsvNummer;
        }

        $zeilen = [];
        $ohneGeschlecht = [];
        $ohneGeburtsdatum = [];
        foreach (new MitgliedRepository($pdo)->alle(false, $stichtag) as $m) {
            if ($m->eintrittsdatum !== null && $m->eintrittsdatum->format('Y-m-d') > $tag) {
                continue;
            }
            if ($m->geschlecht === null) {
                $ohneGeschlecht[] = $m->mitgliedsnummer;
            }
            if ($m->geburtsdatum === null) {
                $ohneGeburtsdatum[] = $m->mitgliedsnummer;
            }

            $kennzeichen = [];
            foreach ($m->abteilungIds as $abteilungId) {
                $kennzeichen[] = $sparten[$abteilungId] ?? '';
            }
            if ($kennzeichen === []) {
                if (!BlsvFormat::OHNE_ABTEILUNG_AUFNEHMEN) {
                    continue;
                }
                $kennzeichen = [''];
            }
            if (!BlsvFormat::ZEILE_JE_ABTEILUNG) {
                $kennzeichen = [implode(',', array_filter($kennzeichen, static fn(string $k): bool => $k !== ''))];
            }

            foreach ($kennzeichen as $sparte) {
                $zeilen[] = new ListenZeile([
                    'titel' => $m->titel,
                    'name' => $m->nachname,
                    'vorname' => $m->vorname,
                    'namenszusatz' => $m->namenszusatz,
                    'geschlecht' => BlsvFormat::geschlecht($m->geschlecht),
                    'geburtsdatum' => $m->geburtsdatum,
                    'spartenkennzeichen' => $sparte,
                ], $m->id);
            }
        }

        $hinweise = [];
        $nummer = VereinRepository::aus($this->dienste)->verbandMitName(BlsvFormat::VERBAND)?->vereinsnummer ?? '';
        if ($nummer !== '') {
            $hinweise[] = 'BLSV-Vereinsnummer für den Upload: ' . $nummer;
        }
        if ($ohneGeschlecht !== []) {
            $hinweise[] = 'Ohne Geschlecht (Mitgliedsnummern): ' . implode(', ', $ohneGeschlecht);
        }
        if ($ohneGeburtsdatum !== []) {
            $hinweise[] = 'Ohne Geburtsdatum (Mitgliedsnummern): ' . implode(', ', $ohneGeburtsdatum);
        }

        return new ListenErgebnis($spalten, $zeilen, $hinweise);
    }

    public function dateiname(array $filter): string
    {
        return 'blsv-meldung-' . self::stichtag($filter)->format('Y-m-d');
    }

    /** @param array<string, string|int|bool> $filter */
    private static function stichtag(array $filter): \DateTimeImmutable
    {
        $gewaehlt = is_string($filter['stichtag'] ?? null) ? $filter['stichtag'] : '';
        $datum = $gewaehlt !== '' ? Datum::ausEingabe($gewaehlt) : null;

        return $datum ?? new \DateTimeImmutable(BlsvFormat::standardStichtag(new \DateTimeImmutable('today')));
    }
}
