<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The "Erweiterte Suche" of the member list (docs/spec/07-mitglieder.md
 * section 8): one field per column, all conditions AND, on top of the free
 * text of MitgliederFilter.
 *
 * Read leniently like the rest of the filter: something unknown or
 * malformed means "not set", never an error.
 *
 * Personal values (names, address, e-mail, phone, IBAN, remark, member
 * number, every date and age) only ever travel in the body of a POST and
 * are NOT part of parameter() - CLAUDE.md section 4. Only the plain
 * choices (ids, sex, payment method, family) may stand in an address, like
 * the other filters.
 */
final readonly class ErweiterteSuche
{
    use Kopierbar;

    /** The choice "no sex given" of the field "geschlecht". */
    public const string OHNE_ANGABE = 'ohne';

    /** The choices of the field "familie" besides the roles. */
    public const string MIT_FAMILIE = 'mit';
    public const string OHNE_FAMILIE = 'ohne';

    public function __construct(
        public string $nachname = '',
        public string $vorname = '',
        public string $strasse = '',
        public string $plz = '',
        public string $ort = '',
        public string $email = '',
        /** Looked for in the phone AND the mobile number. */
        public string $telefon = '',
        /** Without blanks and in upper case; a piece is enough. */
        public string $iban = '',
        public string $bemerkung = '',
        /** Exact. */
        public ?int $mitgliedsnummer = null,
        /** 'm' | 'w' | 'd' | OHNE_ANGABE, or '' for any. */
        public string $geschlecht = '',
        public ?int $funktionId = null,
        public ?Zahlart $zahlart = null,
        /** '' (any), MIT_FAMILIE, OHNE_FAMILIE or the value of a FamilienRolle. */
        public string $familie = '',
        public ?int $alterVon = null,
        public ?int $alterBis = null,
        public ?string $geburtVon = null,
        public ?string $geburtBis = null,
        public ?string $eintrittVon = null,
        public ?string $eintrittBis = null,
        public ?string $austrittVon = null,
        public ?string $austrittBis = null,
    ) {
    }

    /** @param array<string, mixed> $daten form fields and query values */
    public static function aus(array $daten): self
    {
        $geschlecht = self::text($daten, 'geschlecht');
        $familie = self::text($daten, 'familie');

        return new self(
            nachname: self::text($daten, 'nachname'),
            vorname: self::text($daten, 'vorname'),
            strasse: self::text($daten, 'strasse'),
            plz: self::text($daten, 'plz'),
            ort: self::text($daten, 'ort'),
            email: self::text($daten, 'email'),
            telefon: self::text($daten, 'telefon'),
            iban: strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', self::text($daten, 'iban'))),
            bemerkung: self::text($daten, 'bemerkung'),
            mitgliedsnummer: self::zahl($daten, 'mitgliedsnummer', 4294967295),
            geschlecht: Geschlecht::tryFrom($geschlecht) !== null || $geschlecht === self::OHNE_ANGABE ? $geschlecht : '',
            funktionId: self::zahl($daten, 'funktion', PHP_INT_MAX),
            zahlart: Zahlart::tryFrom(self::text($daten, 'zahlart')),
            familie: in_array($familie, [self::MIT_FAMILIE, self::OHNE_FAMILIE], true) || FamilienRolle::tryFrom($familie) !== null ? $familie : '',
            alterVon: self::zahl($daten, 'alter_von', 150, 0),
            alterBis: self::zahl($daten, 'alter_bis', 150, 0),
            geburtVon: self::datum($daten, 'geburt_von'),
            geburtBis: self::datum($daten, 'geburt_bis'),
            eintrittVon: self::datum($daten, 'eintritt_von'),
            eintrittBis: self::datum($daten, 'eintritt_bis'),
            austrittVon: self::datum($daten, 'austritt_von'),
            austrittBis: self::datum($daten, 'austritt_bis'),
        );
    }

    /** True when no field of the extended search is set. */
    public function istLeer(): bool
    {
        foreach (get_object_vars($this) as $wert) {
            if ($wert !== '' && $wert !== null) {
                return false;
            }
        }

        return true;
    }

    /** True when the leaving date is asked for: members who left must then be listed. */
    public function fragtAustritt(): bool
    {
        return $this->austrittVon !== null || $this->austrittBis !== null;
    }

    /**
     * Only the plain choices - NOT the texts, numbers and dates (a name, an
     * address or a birthday does not belong into a URL).
     *
     * @return array<string, string>
     */
    public function parameter(): array
    {
        $parameter = [];
        if ($this->geschlecht !== '') {
            $parameter['geschlecht'] = $this->geschlecht;
        }
        if ($this->funktionId !== null) {
            $parameter['funktion'] = (string) $this->funktionId;
        }
        if ($this->zahlart !== null) {
            $parameter['zahlart'] = $this->zahlart->value;
        }
        if ($this->familie !== '') {
            $parameter['familie'] = $this->familie;
        }

        return $parameter;
    }

    /** @param array<string, mixed> $daten */
    private static function text(array $daten, string $name): string
    {
        $wert = $daten[$name] ?? '';

        return is_string($wert) ? trim($wert) : '';
    }

    /** @param array<string, mixed> $daten */
    private static function zahl(array $daten, string $name, int $max, int $min = 1): ?int
    {
        $wert = self::text($daten, $name);
        if (preg_match('/^[0-9]{1,18}$/D', $wert) !== 1) {
            return null;
        }
        $zahl = (int) $wert;

        return $zahl >= $min && $zahl <= $max ? $zahl : null;
    }

    /** @param array<string, mixed> $daten */
    private static function datum(array $daten, string $name): ?string
    {
        $wert = self::text($daten, $name);
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $wert, $t) !== 1) {
            return null;
        }

        return checkdate((int) $t[2], (int) $t[3], (int) $t[1]) && (int) $t[1] >= 1000 ? $wert : null;
    }
}
