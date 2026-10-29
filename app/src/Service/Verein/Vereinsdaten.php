<?php

declare(strict_types=1);

namespace App\Service\Verein;

/**
 * The club data of docs/spec/11-vereinsdaten.md section 1 except the
 * associations and the logo: one value per field, all optional, all plain
 * text (public information, stored unencrypted).
 *
 * The field list is the single source for the form, the JSON in the
 * `setting` table and the readers (direct debit file, header, mails).
 */
final readonly class Vereinsdaten
{
    /** Field key => label, in the order of the form. */
    public const array FELDER = [
        'vereinsname' => 'Vereinsname',
        'kurzname' => 'Kurzname',
        'strasse' => 'Straße',
        'plz' => 'PLZ',
        'ort' => 'Ort',
        'telefon' => 'Telefon',
        'email' => 'E-Mail',
        'website' => 'Website',
        'registergericht' => 'Registergericht',
        'registernummer' => 'Vereinsregister-Nummer',
        'finanzamt' => 'Finanzamt',
        'steuernummer' => 'Steuernummer',
        'freistellungsdatum' => 'Datum des letzten Freistellungsbescheids',
        'kontoinhaber' => 'Kontoinhaber',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'bankname' => 'Name der Bank',
        'glaeubiger_id' => 'Gläubiger-ID',
        'mandatsdatum' => 'Standard-Mandatsdatum',
        'verwendungszweck' => 'Vorlage für den Verwendungszweck',
    ];

    /** Fields that hold a date (Y-m-d). */
    private const array DATUMSFELDER = ['freistellungsdatum', 'mandatsdatum'];

    /** @param array<string, string> $werte every key of FELDER */
    private function __construct(private array $werte)
    {
    }

    /** All fields empty. */
    public static function leer(): self
    {
        return new self(array_fill_keys(array_keys(self::FELDER), ''));
    }

    /**
     * From the stored JSON; unknown keys and non-strings are ignored.
     *
     * @param array<array-key, mixed> $gespeichert
     */
    public static function ausGespeichertem(array $gespeichert): self
    {
        $werte = self::leer()->werte;
        foreach ($werte as $schluessel => $_) {
            $wert = $gespeichert[$schluessel] ?? '';
            $werte[$schluessel] = is_string($wert) ? $wert : '';
        }

        return new self($werte);
    }

    /**
     * From the form: trimmed, IBAN/BIC/creditor ID normalised, an invalid
     * date dropped (it cannot be stored as a date). Everything else is kept
     * as typed - the rules are hints (CLAUDE.md "Hinweise statt Sperren").
     */
    public static function ausEingabe(callable $feld): self
    {
        $werte = [];
        foreach (self::FELDER as $schluessel => $_) {
            $wert = trim((string) $feld($schluessel));
            $werte[$schluessel] = match ($schluessel) {
                'iban' => Iban::normalisieren($wert),
                'bic', 'glaeubiger_id' => strtoupper((string) preg_replace('/\s+/', '', $wert)),
                default => $wert,
            };
            if (in_array($schluessel, self::DATUMSFELDER, true) && !self::istDatum($werte[$schluessel])) {
                $werte[$schluessel] = '';
            }
        }

        return new self($werte);
    }

    /** @return array<string, string> for the JSON in the `setting` table */
    public function alsListe(): array
    {
        return $this->werte;
    }

    public function wert(string $schluessel): string
    {
        return $this->werte[$schluessel] ?? '';
    }

    /** Short name for the header and file names, else the full name. */
    public function anzeigename(): string
    {
        return $this->werte['kurzname'] !== '' ? $this->werte['kurzname'] : $this->werte['vereinsname'];
    }

    /** Without both, no direct debit file can be made (08 section 4). */
    public function lastschriftMoeglich(): bool
    {
        return $this->werte['iban'] !== '' && $this->werte['glaeubiger_id'] !== '';
    }

    /**
     * What to point out after saving - the entry is kept anyway.
     *
     * @param list<string> $verworfeneDatumsfelder labels of dates that were not valid
     * @return list<string>
     */
    public function hinweise(array $verworfeneDatumsfelder = []): array
    {
        $hinweise = [];
        if ($this->werte['iban'] !== '' && !Iban::istGueltig($this->werte['iban'])) {
            $hinweise[] = 'Die IBAN ist nicht gültig (Prüfziffer).';
        }
        if ($this->werte['email'] !== '' && filter_var($this->werte['email'], FILTER_VALIDATE_EMAIL) === false) {
            $hinweise[] = 'Die E-Mail-Adresse sieht nicht gültig aus.';
        }
        foreach ($verworfeneDatumsfelder as $bezeichnung) {
            $hinweise[] = $bezeichnung . ': Das Datum war nicht gültig und wurde nicht gespeichert.';
        }

        return $hinweise;
    }

    /**
     * Labels of the date fields the form filled with something that is not a date.
     *
     * @return list<string>
     */
    public static function ungueltigeDaten(callable $feld): array
    {
        $ungueltig = [];
        foreach (self::DATUMSFELDER as $schluessel) {
            $wert = trim((string) $feld($schluessel));
            if ($wert !== '' && !self::istDatum($wert)) {
                $ungueltig[] = self::FELDER[$schluessel];
            }
        }

        return $ungueltig;
    }

    private static function istDatum(string $wert): bool
    {
        if ($wert === '') {
            return true;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $datum !== false && $datum->format('Y-m-d') === $wert;
    }
}
