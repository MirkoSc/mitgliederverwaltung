<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Alter;
use App\Support\Iban;

/**
 * A member (docs/spec/07-mitglieder.md sections 1 and 8) as
 * App\Repository\MitgliedRepository reads and saves it.
 *
 * Only Mitgliedsnummer and Nachname are required; everything else may be
 * missing, which gives a hint and never blocks (CLAUDE.md). Missing text is
 * '', a missing date or choice is null.
 *
 * Immutable: a change is a copy, `clone($mitglied, ['ort' => 'Neustadt'])`
 * (PHP 8.5), which the repository then saves as a new version.
 */
final readonly class Mitglied
{
    /** The country a new member gets (07 section 1). */
    public const string LAND_DEFAULT = 'D';

    /**
     * @param list<int> $abteilungIds departments (07 section 2), 0..n
     * @param list<int> $funktionIds functions (07 section 4), 0..n
     */
    public function __construct(
        public int $mitgliedsnummer,
        public string $nachname,
        public string $vorname = '',
        public string $anrede = '',
        public string $titel = '',
        public string $namenszusatz = '',
        public ?Geschlecht $geschlecht = null,
        public ?\DateTimeImmutable $geburtsdatum = null,
        public string $strasse = '',
        public string $plz = '',
        public string $ort = '',
        public string $land = self::LAND_DEFAULT,
        public string $telefon = '',
        public string $handy = '',
        public string $email = '',
        /** Null = "unbekannt". */
        public ?\DateTimeImmutable $eintrittsdatum = null,
        public ?\DateTimeImmutable $austrittsdatum = null,
        public string $austrittsgrund = '',
        /** The tick "aktiv"; false = passiv. */
        public bool $aktiv = true,
        public array $abteilungIds = [],
        /** Null = no fee type (family members other than the payer). */
        public ?int $beitragsartId = null,
        public Zahlart $zahlart = Zahlart::Lastschrift,
        /** Normalised on save; an invalid one is kept (hint). */
        public string $iban = '',
        public string $bic = '',
        /** Only when it differs from the member. */
        public string $kontoinhaber = '',
        /** As entered; '' means the default, see effektiveMandatsreferenz(). */
        public string $mandatsreferenz = '',
        /** As entered; null means the default, see effektivesMandatsdatum(). */
        public ?\DateTimeImmutable $mandatsdatum = null,
        public ?int $familieId = null,
        public ?FamilienRolle $familieRolle = null,
        public array $funktionIds = [],
        public string $lizenznummer = '',
        public string $bemerkung = '',
        /** Where the member came from (docs/spec/10-import.md); not versioned. */
        public Herkunft $herkunft = Herkunft::Manuell,
        /** Null until saved. */
        public ?int $id = null,
        /** Number of the latest version; 0 until saved. */
        public int $version = 0,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /** "Ehemalig" (07 section 1): Austrittsdatum set and reached - computed, never stored. */
    public function istEhemalig(\DateTimeInterface $stichtag): bool
    {
        return $this->austrittsdatum !== null && $this->austrittsdatum->format('Y-m-d') <= $stichtag->format('Y-m-d');
    }

    /** Age in whole years on that day; null without a birth date. */
    public function alterAm(\DateTimeInterface $stichtag): ?int
    {
        return $this->geburtsdatum === null ? null : Alter::am($this->geburtsdatum, $stichtag);
    }

    public function hatGueltigeIban(): bool
    {
        return Iban::istGueltig($this->iban);
    }

    /** "Vorname Nachname" with title and suffix, for display. */
    public function anzeigeName(): string
    {
        $teile = array_filter(
            [$this->titel, $this->vorname, $this->nachname, $this->namenszusatz],
            static fn(string $teil): bool => $teil !== '',
        );

        return implode(' ', $teile);
    }

    /** Name for the direct debit: the account holder if different, else the member. */
    public function kontoinhaberName(): string
    {
        return $this->kontoinhaber !== '' ? $this->kontoinhaber : trim($this->vorname . ' ' . $this->nachname);
    }

    /** Mandate reference (07 section 1): as entered, by default the member number. */
    public function effektiveMandatsreferenz(): string
    {
        return $this->mandatsreferenz !== '' ? $this->mandatsreferenz : (string) $this->mitgliedsnummer;
    }

    /**
     * Mandate date (07 section 1): as entered, by default the date of
     * entry, else the club's default mandate date (11) the caller passes.
     */
    public function effektivesMandatsdatum(?\DateTimeImmutable $vereinsDefault): ?\DateTimeImmutable
    {
        return $this->mandatsdatum ?? $this->eintrittsdatum ?? $vereinsDefault;
    }

    /**
     * The complete state of all fields of 07 section 1, as a version stores
     * it (07 section 7) and as the repository compares it to decide whether
     * a save changed anything. Stable on purpose: snake_case keys like the
     * columns, dates as "Y-m-d", enums as their value, id lists sorted.
     *
     * Not in here: id, version, timestamps and the origin - they describe
     * the row, not the member.
     *
     * @return array<string, scalar|list<int>|null>
     */
    public function daten(): array
    {
        return [
            'mitgliedsnummer' => $this->mitgliedsnummer,
            'anrede' => $this->anrede,
            'titel' => $this->titel,
            'vorname' => $this->vorname,
            'nachname' => $this->nachname,
            'namenszusatz' => $this->namenszusatz,
            'geschlecht' => $this->geschlecht?->value,
            'geburtsdatum' => $this->geburtsdatum?->format('Y-m-d'),
            'strasse' => $this->strasse,
            'plz' => $this->plz,
            'ort' => $this->ort,
            'land' => $this->land,
            'telefon' => $this->telefon,
            'handy' => $this->handy,
            'email' => $this->email,
            'eintrittsdatum' => $this->eintrittsdatum?->format('Y-m-d'),
            'austrittsdatum' => $this->austrittsdatum?->format('Y-m-d'),
            'austrittsgrund' => $this->austrittsgrund,
            'aktiv' => $this->aktiv,
            'abteilungen' => self::sortiert($this->abteilungIds),
            'beitragsart_id' => $this->beitragsartId,
            'zahlart' => $this->zahlart->value,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'kontoinhaber' => $this->kontoinhaber,
            'mandatsreferenz' => $this->mandatsreferenz,
            'mandatsdatum' => $this->mandatsdatum?->format('Y-m-d'),
            'familie_id' => $this->familieId,
            'familie_rolle' => $this->familieRolle?->value,
            'funktionen' => self::sortiert($this->funktionIds),
            'lizenznummer' => $this->lizenznummer,
            'bemerkung' => $this->bemerkung,
        ];
    }

    /**
     * The member from a version's data (the inverse of daten()) - what
     * "Zurückholen" (07 section 7) starts from. Row fields (id, version,
     * timestamps, origin) come from $aktuell, the member as it is now.
     * Unknown or missing keys fall back to the defaults, so an older
     * version format still reads.
     *
     * @param array<string, mixed> $daten
     */
    public static function ausDaten(array $daten, self $aktuell): self
    {
        $text = static fn(string $schluessel, string $vorgabe = ''): string => is_scalar($daten[$schluessel] ?? null) ? (string) $daten[$schluessel] : $vorgabe;
        $zahl = static fn(string $schluessel): ?int => is_int($daten[$schluessel] ?? null) ? $daten[$schluessel] : null;
        $datum = static fn(string $schluessel): ?\DateTimeImmutable => is_string($daten[$schluessel] ?? null) ? self::datum($daten[$schluessel]) : null;
        $ids = static fn(string $schluessel): array => is_array($daten[$schluessel] ?? null)
            ? self::sortiert(array_map(intval(...), array_filter($daten[$schluessel], is_int(...))))
            : [];

        return new self(
            mitgliedsnummer: $zahl('mitgliedsnummer') ?? $aktuell->mitgliedsnummer,
            nachname: $text('nachname', $aktuell->nachname),
            vorname: $text('vorname'),
            anrede: $text('anrede'),
            titel: $text('titel'),
            namenszusatz: $text('namenszusatz'),
            geschlecht: Geschlecht::tryFrom($text('geschlecht')),
            geburtsdatum: $datum('geburtsdatum'),
            strasse: $text('strasse'),
            plz: $text('plz'),
            ort: $text('ort'),
            land: $text('land', self::LAND_DEFAULT),
            telefon: $text('telefon'),
            handy: $text('handy'),
            email: $text('email'),
            eintrittsdatum: $datum('eintrittsdatum'),
            austrittsdatum: $datum('austrittsdatum'),
            austrittsgrund: $text('austrittsgrund'),
            aktiv: ($daten['aktiv'] ?? true) === true,
            abteilungIds: $ids('abteilungen'),
            beitragsartId: $zahl('beitragsart_id'),
            zahlart: Zahlart::tryFrom($text('zahlart')) ?? Zahlart::Lastschrift,
            iban: $text('iban'),
            bic: $text('bic'),
            kontoinhaber: $text('kontoinhaber'),
            mandatsreferenz: $text('mandatsreferenz'),
            mandatsdatum: $datum('mandatsdatum'),
            familieId: $zahl('familie_id'),
            familieRolle: FamilienRolle::tryFrom($text('familie_rolle')),
            funktionIds: $ids('funktionen'),
            lizenznummer: $text('lizenznummer'),
            bemerkung: $text('bemerkung'),
            herkunft: $aktuell->herkunft,
            id: $aktuell->id,
            version: $aktuell->version,
            createdAt: $aktuell->createdAt,
            updatedAt: $aktuell->updatedAt,
        );
    }

    /** A calendar day "Y-m-d" at midnight, local time; null for anything else. */
    public static function datum(?string $wert): ?\DateTimeImmutable
    {
        if ($wert === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $wert) !== 1) {
            return null;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
    }

    /**
     * @param array<int> $ids
     * @return list<int>
     */
    private static function sortiert(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
