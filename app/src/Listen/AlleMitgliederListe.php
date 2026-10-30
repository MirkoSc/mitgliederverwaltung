<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Mitglied;
use App\Domain\Permission;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FamilieRepository;
use App\Repository\FunktionRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;

/**
 * "Alle Mitglieder" (docs/spec/09-listen-und-exporte.md section 5): one row
 * per member with every field of 07 section 1 in readable form, for a later
 * move to other software. The fee columns (fee type, payment, bank data,
 * mandate, annual fee of the current year) are only for accounts that may
 * read fee data (beitraege.sehen, today every role).
 */
final class AlleMitgliederListe implements Liste
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
        return 'alle-mitglieder';
    }

    public function titel(): string
    {
        return 'Alle Mitglieder';
    }

    public function beschreibung(): string
    {
        return 'Gesamtexport: eine Zeile je Mitglied mit allen Feldern.';
    }

    public function reihenfolge(): int
    {
        return 70;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function filter(): array
    {
        return [ListenFilter::haken('ehemalige', 'Ehemalige einschließen')];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $pdo = $this->dienste->pdo();
        $mitBeitrag = $konto->darf(Permission::BeitraegeSehen);
        $jahr = (int) new \DateTimeImmutable('today')->format('Y');

        $spalten = [
            Spalte::zahl('mitgliedsnummer', 'Mitgliedsnummer'),
            Spalte::text('anrede', 'Anrede'),
            Spalte::text('titel', 'Titel'),
            Spalte::text('vorname', 'Vorname'),
            Spalte::text('nachname', 'Nachname'),
            Spalte::text('namenszusatz', 'Namenszusatz'),
            Spalte::text('geschlecht', 'Geschlecht'),
            Spalte::datum('geburtsdatum', 'Geburtsdatum'),
            Spalte::text('strasse', 'Straße'),
            Spalte::text('plz', 'PLZ'),
            Spalte::text('ort', 'Ort'),
            Spalte::text('land', 'Land'),
            Spalte::text('telefon', 'Telefon'),
            Spalte::text('handy', 'Handy'),
            Spalte::text('email', 'E-Mail'),
            Spalte::datum('eintrittsdatum', 'Eintrittsdatum'),
            Spalte::datum('austrittsdatum', 'Austrittsdatum'),
            Spalte::text('austrittsgrund', 'Austrittsgrund'),
            Spalte::text('aktiv', 'Aktiv/Passiv'),
            Spalte::text('abteilungen', 'Abteilungen'),
            Spalte::text('funktionen', 'Funktionen'),
            Spalte::text('lizenznummer', 'Lizenznummer'),
            Spalte::text('familie', 'Familie'),
            Spalte::text('bemerkung', 'Bemerkung'),
        ];
        if ($mitBeitrag) {
            array_push(
                $spalten,
                Spalte::text('beitragsart', 'Beitragsart'),
                Spalte::text('zahlart', 'Zahlart'),
                Spalte::text('iban', 'IBAN'),
                Spalte::text('bic', 'BIC'),
                Spalte::text('kontoinhaber', 'Kontoinhaber'),
                Spalte::text('mandatsreferenz', 'Mandatsreferenz'),
                Spalte::datum('mandatsdatum', 'Mandatsdatum'),
                Spalte::geld('beitrag_betrag', 'Jahresbeitrag ' . $jahr),
                Spalte::text('beitrag_status', 'Status Jahresbeitrag ' . $jahr),
            );
        }

        $abteilungen = $this->namen(new AbteilungRepository($pdo)->alle());
        $funktionen = $this->namen(new FunktionRepository($pdo)->alle());
        $arten = [];
        $beitraege = [];
        if ($mitBeitrag) {
            foreach (new BeitragsartRepository($pdo)->alle() as $art) {
                $arten[(int) $art->id] = $art->name;
            }
            foreach (new JahresbeitragRepository($pdo)->imJahr($jahr) as $beitrag) {
                $beitraege[$beitrag->mitgliedId] = $beitrag;
            }
        }
        $familienRepo = new FamilieRepository($pdo);
        $familienNamen = [];

        $zeilen = [];
        foreach (new MitgliedRepository($pdo)->alle($filter['ehemalige'] === true) as $m) {
            $familie = '';
            if ($m->familieId !== null) {
                $familienNamen[$m->familieId] ??= $familienRepo->finde($m->familieId)?->name ?? '';
                $familie = trim($familienNamen[$m->familieId] . ($m->familieRolle !== null ? ' (' . $m->familieRolle->bezeichnung() . ')' : ''));
            }
            $werte = [
                'mitgliedsnummer' => $m->mitgliedsnummer,
                'anrede' => $m->anrede,
                'titel' => $m->titel,
                'vorname' => $m->vorname,
                'nachname' => $m->nachname,
                'namenszusatz' => $m->namenszusatz,
                'geschlecht' => $m->geschlecht?->bezeichnung() ?? '',
                'geburtsdatum' => $m->geburtsdatum,
                'strasse' => $m->strasse,
                'plz' => $m->plz,
                'ort' => $m->ort,
                'land' => $m->land,
                'telefon' => $m->telefon,
                'handy' => $m->handy,
                'email' => $m->email,
                'eintrittsdatum' => $m->eintrittsdatum,
                'austrittsdatum' => $m->austrittsdatum,
                'austrittsgrund' => $m->austrittsgrund,
                'aktiv' => $m->aktiv ? 'aktiv' : 'passiv',
                'abteilungen' => self::aufzaehlen($m->abteilungIds, $abteilungen),
                'funktionen' => self::aufzaehlen($m->funktionIds, $funktionen),
                'lizenznummer' => $m->lizenznummer,
                'familie' => $familie,
                'bemerkung' => $m->bemerkung,
            ];
            if ($mitBeitrag) {
                $beitrag = $beitraege[(int) $m->id] ?? null;
                $werte += [
                    'beitragsart' => $m->beitragsartId !== null ? ($arten[$m->beitragsartId] ?? '') : '',
                    'zahlart' => $m->zahlart->bezeichnung(),
                    'iban' => $m->iban,
                    'bic' => $m->bic,
                    'kontoinhaber' => $m->kontoinhaber,
                    'mandatsreferenz' => $m->mandatsreferenz,
                    'mandatsdatum' => $m->mandatsdatum,
                    'beitrag_betrag' => $beitrag?->betragCent,
                    'beitrag_status' => $beitrag?->status->bezeichnung() ?? '',
                ];
            }
            $zeilen[] = new ListenZeile($werte, $m->id);
        }

        return new ListenErgebnis($spalten, $zeilen);
    }

    public function dateiname(array $filter): string
    {
        return 'alle-mitglieder-' . new \DateTimeImmutable('today')->format('Y-m-d');
    }

    /**
     * @param list<object{id: ?int, name: string}> $eintraege
     * @return array<int, string>
     */
    private function namen(array $eintraege): array
    {
        $namen = [];
        foreach ($eintraege as $e) {
            $namen[(int) $e->id] = $e->name;
        }

        return $namen;
    }

    /**
     * @param list<int> $ids
     * @param array<int, string> $namen in display order
     */
    private static function aufzaehlen(array $ids, array $namen): string
    {
        $treffer = [];
        foreach ($namen as $id => $name) {
            if (in_array($id, $ids, true)) {
                $treffer[] = $name;
            }
        }

        return implode(', ', $treffer);
    }
}
