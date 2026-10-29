<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Domain\Geschlecht;
use App\Domain\Mitglied;
use App\Domain\Zahlart;
use App\Support\Iban;

/**
 * The member form, tab "Daten" (docs/spec/07-mitglieder.md section 1):
 * the values it shows and reading back what it sent.
 *
 * Only two things block a save: a missing or unusable member number and a
 * missing surname (07 section 1, "Pflichtfelder nur Nummer + Nachname"), plus
 * a date the server cannot read - keeping it would silently lose what was
 * typed. Everything else is saved as entered; what looks wrong becomes a
 * hint, never an error (CLAUDE.md "Hinweise statt Sperren").
 *
 * The family and its role are not fields of this form: they are kept from
 * the member as it is stored (families are maintained in the member on
 * their own, 07 section 3).
 */
final readonly class MitgliedFormular
{
    /** Largest number the column `mitglied.mitgliedsnummer` (INT UNSIGNED) takes. */
    public const int GROESSTE_NUMMER = 4294967295;

    /** Suggestions for "Austrittsgrund" (07 section 1) - free text, not a choice. */
    public const array AUSTRITTSGRUENDE = ['Austritt', 'verstorben'];

    /**
     * Text fields with the width of their column (migrations/041): the
     * input gets it as maxlength, the server cuts anything longer.
     */
    public const array LAENGEN = [
        'anrede' => 32, 'titel' => 64, 'vorname' => 100, 'nachname' => 100, 'namenszusatz' => 64,
        'strasse' => 150, 'plz' => 10, 'ort' => 100, 'land' => 3, 'telefon' => 50, 'handy' => 50,
        'email' => 254, 'austrittsgrund' => 255, 'iban' => 42, 'bic' => 11, 'kontoinhaber' => 140,
        'mandatsreferenz' => 35, 'lizenznummer' => 64, 'bemerkung' => 10000,
    ];

    /** Date fields of the form; '' = none (for Eintrittsdatum: "unbekannt"). */
    public const array DATUMSFELDER = ['geburtsdatum', 'eintrittsdatum', 'austrittsdatum', 'mandatsdatum'];

    /**
     * @param array<string, string> $werte field => value as the form shows it
     * @param list<int> $abteilungIds
     * @param list<int> $funktionIds
     * @param array<string, string> $fehler field => what to do about it
     * @param Mitglied|null $mitglied the member read from the input; null while there are errors
     */
    private function __construct(
        public array $werte,
        public array $abteilungIds,
        public array $funktionIds,
        public array $fehler = [],
        public ?Mitglied $mitglied = null,
    ) {
    }

    /** An empty form for a new member, with the next free number. */
    public static function neu(int $naechsteNummer): self
    {
        return self::aus(new Mitglied($naechsteNummer, ''));
    }

    /** The form showing a member as it is stored. */
    public static function aus(Mitglied $m): self
    {
        $werte = [];
        foreach ($m->daten() as $feld => $wert) {
            if (!is_array($wert)) {
                $werte[$feld] = match (true) {
                    is_bool($wert) => $wert ? '1' : '0',
                    default => (string) $wert,
                };
            }
        }
        $werte['iban'] = $m->iban === '' ? '' : Iban::anzeigen($m->iban);

        return new self($werte, $m->abteilungIds, $m->funktionIds, mitglied: $m);
    }

    /**
     * Reads the posted form onto $basis (the stored member, or an empty one
     * for a new member): every field of the form replaces the stored value,
     * family, origin and the row fields stay.
     *
     * Ids of departments, functions and fee types that do not exist (any
     * more) are dropped.
     *
     * @param array<string, mixed> $post
     */
    public static function gelesen(array $post, Mitglied $basis, Auswahllisten $listen): self
    {
        $text = static function (string $feld) use ($post): string {
            $wert = $post[$feld] ?? '';
            $wert = is_string($wert) ? trim($wert) : '';
            if ($feld !== 'bemerkung') {
                $wert = (string) preg_replace('/\s+/u', ' ', $wert);
            }

            return mb_substr($wert, 0, self::LAENGEN[$feld] ?? 255);
        };
        $ids = static function (string $feld) use ($post): array {
            $roh = $post[$feld] ?? [];
            $ids = [];
            foreach (is_array($roh) ? $roh : [] as $wert) {
                if (is_string($wert) && preg_match('/^[1-9][0-9]{0,17}$/D', $wert) === 1) {
                    $ids[] = (int) $wert;
                }
            }

            return array_values(array_unique($ids));
        };

        $werte = [];
        foreach (array_keys(self::LAENGEN) as $feld) {
            $werte[$feld] = $text($feld);
        }
        foreach ([...self::DATUMSFELDER, 'mitgliedsnummer', 'geschlecht', 'beitragsart_id', 'zahlart', 'aktiv'] as $feld) {
            $werte[$feld] = $text($feld);
        }
        $abteilungIds = Auswahllisten::vorhandene($ids('abteilungen'), $listen->abteilungen);
        $funktionIds = Auswahllisten::vorhandene($ids('funktionen'), $listen->funktionen);

        $fehler = [];
        $nummer = $werte['mitgliedsnummer'];
        if ($nummer === '') {
            $fehler['mitgliedsnummer'] = 'Bitte eine Mitgliedsnummer eintragen.';
        } elseif (!ctype_digit($nummer) || (int) $nummer < 1 || strlen(ltrim($nummer, '0')) > 10 || (int) $nummer > self::GROESSTE_NUMMER) {
            $fehler['mitgliedsnummer'] = 'Die Mitgliedsnummer ist eine ganze Zahl ab 1, ohne Buchstaben oder Zeichen.';
        }
        if ($werte['nachname'] === '') {
            $fehler['nachname'] = 'Bitte den Nachnamen eintragen.';
        }
        $daten = [];
        foreach (self::DATUMSFELDER as $feld) {
            $daten[$feld] = self::datum($werte[$feld]);
            if ($werte[$feld] !== '' && $daten[$feld] === null) {
                $fehler[$feld] = 'Bitte das Datum als Tag, Monat und Jahr eintragen, zum Beispiel 31.12.1980.';
            } elseif ($daten[$feld] !== null) {
                $werte[$feld] = $daten[$feld]->format('Y-m-d'); // what the date input shows
            }
        }

        if ($fehler !== []) {
            return new self($werte, $abteilungIds, $funktionIds, $fehler);
        }

        $beitragsartId = ctype_digit($werte['beitragsart_id']) ? (int) $werte['beitragsart_id'] : 0;
        $beitragsartId = Auswahllisten::vorhandene([$beitragsartId], $listen->beitragsarten)[0] ?? null;

        $mitglied = $basis->mit([
            'mitgliedsnummer' => (int) $nummer,
            'anrede' => $werte['anrede'],
            'titel' => $werte['titel'],
            'vorname' => $werte['vorname'],
            'nachname' => $werte['nachname'],
            'namenszusatz' => $werte['namenszusatz'],
            'geschlecht' => Geschlecht::tryFrom($werte['geschlecht']),
            'geburtsdatum' => $daten['geburtsdatum'],
            'strasse' => $werte['strasse'],
            'plz' => $werte['plz'],
            'ort' => $werte['ort'],
            'land' => $werte['land'] === '' ? Mitglied::LAND_DEFAULT : mb_strtoupper($werte['land']),
            'telefon' => $werte['telefon'],
            'handy' => $werte['handy'],
            'email' => $werte['email'],
            'eintrittsdatum' => $daten['eintrittsdatum'],
            'austrittsdatum' => $daten['austrittsdatum'],
            'austrittsgrund' => $werte['austrittsgrund'],
            'aktiv' => $werte['aktiv'] !== '0',
            'abteilungIds' => $abteilungIds,
            'beitragsartId' => $beitragsartId,
            'zahlart' => Zahlart::tryFrom($werte['zahlart']) ?? Zahlart::Lastschrift,
            // The repository normalises too; done here so the column width holds.
            'iban' => mb_substr(Iban::normalisieren($werte['iban']), 0, 34),
            'bic' => $werte['bic'],
            'kontoinhaber' => $werte['kontoinhaber'],
            'mandatsreferenz' => $werte['mandatsreferenz'],
            'mandatsdatum' => $daten['mandatsdatum'],
            'funktionIds' => $funktionIds,
            'lizenznummer' => $werte['lizenznummer'],
            'bemerkung' => $werte['bemerkung'],
        ]);

        return new self($werte, $abteilungIds, $funktionIds, mitglied: $mitglied);
    }

    /**
     * A day as the date input sends it ("1980-12-31") or as typed where the
     * browser shows a plain text field ("31.12.1980"); null for '' and for
     * anything else.
     */
    public static function datum(string $wert): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', $wert, $teile) === 1) {
            $wert = sprintf('%04d-%02d-%02d', $teile[3], $teile[2], $teile[1]);
        }

        return Mitglied::datum($wert === '' ? null : $wert);
    }

    /** The same form with one more error (from the save itself). */
    public function mitFehler(string $feld, string $text): self
    {
        return new self($this->werte, $this->abteilungIds, $this->funktionIds, [...$this->fehler, $feld => $text]);
    }

    public function wert(string $feld): string
    {
        return $this->werte[$feld] ?? '';
    }

    public function fehler(string $feld): ?string
    {
        return $this->fehler[$feld] ?? null;
    }

    /**
     * What looks wrong after a save - shown, never blocking (07 section 1).
     * The full set of member hints (07 section 6) is computed elsewhere.
     *
     * @return list<string>
     */
    public static function hinweise(Mitglied $m): array
    {
        $hinweise = [];
        if ($m->iban !== '' && !$m->hatGueltigeIban()) {
            $hinweise[] = 'Die IBAN ist ungültig – die Prüfziffer passt nicht. Bitte die IBAN prüfen; gespeichert wurde sie trotzdem.';
        }

        return $hinweise;
    }
}
