<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Zahlart;
use App\Support\Datum;

/**
 * Names and readable values of the fields of Mitglied::daten() for the
 * section "Geändert" of the merge view ("in der Anwendung → in der Datei",
 * docs/spec/10-import.md section 4): ids become names, dates German, empty
 * values "leer".
 */
final readonly class AbgleichFelder
{
    private const array NAMEN = [
        'mitgliedsnummer' => 'Mitgliedsnummer',
        'anrede' => 'Anrede',
        'titel' => 'Titel',
        'vorname' => 'Vorname',
        'nachname' => 'Nachname',
        'namenszusatz' => 'Namenszusatz',
        'geschlecht' => 'Geschlecht',
        'geburtsdatum' => 'Geburtsdatum',
        'strasse' => 'Straße',
        'plz' => 'PLZ',
        'ort' => 'Ort',
        'land' => 'Land',
        'telefon' => 'Telefon',
        'handy' => 'Handy',
        'email' => 'E-Mail',
        'eintrittsdatum' => 'Eintritt',
        'austrittsdatum' => 'Austritt',
        'austrittsgrund' => 'Austrittsgrund',
        'aktiv' => 'Aktiv/passiv',
        'abteilungen' => 'Abteilungen',
        'beitragsart_id' => 'Beitragsart',
        'zahlart' => 'Zahlart',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'kontoinhaber' => 'Kontoinhaber',
        'mandatsreferenz' => 'Mandatsreferenz',
        'mandatsdatum' => 'Mandatsdatum',
        'familie_id' => 'Familie',
        'familie_rolle' => 'Rolle in der Familie',
        'funktionen' => 'Funktionen',
        'lizenznummer' => 'Lizenznummer',
        'bemerkung' => 'Bemerkung',
    ];

    /** Longer text (a remark) is cut for the overview; the member page shows it whole. */
    private const int MAX_ZEICHEN = 120;

    /**
     * @param array<int, string> $abteilungen id => name
     * @param array<int, string> $beitragsarten id => name
     * @param array<int, string> $funktionen id => name
     * @param array<int, string> $familien id => name of the families in the application
     * @param array<string, string> $neueFamilien key => name of the families in the file
     */
    public function __construct(
        private array $abteilungen,
        private array $beitragsarten,
        private array $funktionen,
        private array $familien,
        private array $neueFamilien,
    ) {
    }

    public function name(string $feld): string
    {
        return self::NAMEN[$feld] ?? $feld;
    }

    /** The value as the page shows it; '' stands for "leer". */
    public function wert(string $feld, mixed $wert): string
    {
        if ($wert === null || $wert === '' || $wert === []) {
            return '';
        }

        $text = match ($feld) {
            'geburtsdatum', 'eintrittsdatum', 'austrittsdatum', 'mandatsdatum' => Datum::anzeigen((string) $wert),
            'aktiv' => $wert === true ? 'aktiv' : 'passiv',
            'geschlecht' => Geschlecht::tryFrom((string) $wert)?->bezeichnung() ?? (string) $wert,
            'zahlart' => Zahlart::tryFrom((string) $wert)?->bezeichnung() ?? (string) $wert,
            'familie_rolle' => FamilienRolle::tryFrom((string) $wert)?->bezeichnung() ?? (string) $wert,
            'abteilungen' => $this->namen($this->abteilungen, $wert),
            'funktionen' => $this->namen($this->funktionen, $wert),
            'beitragsart_id' => $this->beitragsarten[(int) $wert] ?? '#' . (int) $wert,
            'familie_id' => $this->familie($wert),
            default => is_scalar($wert) ? (string) $wert : '',
        };

        return mb_strlen($text) > self::MAX_ZEICHEN ? mb_substr($text, 0, self::MAX_ZEICHEN - 1) . '…' : $text;
    }

    /** Name of the family a line of the file belongs to (by its key). */
    public function familieDerDatei(?string $schluessel): string
    {
        return $schluessel === null ? '' : ($this->neueFamilien[$schluessel] ?? $schluessel);
    }

    private function familie(mixed $wert): string
    {
        if (is_string($wert) && str_starts_with($wert, AbgleichVergleich::NEUE_FAMILIE)) {
            $schluessel = substr($wert, strlen(AbgleichVergleich::NEUE_FAMILIE));

            return sprintf('neue Familie „%s“', $this->neueFamilien[$schluessel] ?? $schluessel);
        }

        return $this->familien[(int) $wert] ?? 'Familie #' . (int) $wert;
    }

    /**
     * @param array<int, string> $namen
     */
    private function namen(array $namen, mixed $ids): string
    {
        if (!is_array($ids)) {
            return '';
        }
        $liste = array_map(static fn(mixed $id): string => $namen[(int) $id] ?? '#' . (int) $id, $ids);
        sort($liste);

        return implode(', ', $liste);
    }
}
