<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What the member list shows: the search text, the filters, the sort order
 * and the page (docs/spec/06-oberflaeche.md section 2, 07 section 8).
 *
 * Former members are hidden unless asked for. Every value from outside is
 * read leniently: something unknown means the default, never an error - a
 * mistyped URL still shows a list.
 */
final readonly class MitgliederFilter
{
    use Kopierbar;

    /** 50 per page are enough for a club (06 section 3) - the default. */
    public const int SEITENGROESSE = 50;

    /** What "Je Seite" offers; 0 means all members on one page. */
    public const array ANZAHLEN = [50, 100, 200, 0];

    /** The URL value of "all on one page". */
    private const string ALLE = 'alle';

    /** Suffix of the sort value for descending order ("name-ab"). */
    private const string ABSTEIGEND = '-ab';

    public function __construct(
        /** Words, all of which must occur in a search field (07 section 8). */
        public string $text = '',
        public ?int $abteilungId = null,
        public MitgliederStatus $status = MitgliederStatus::Alle,
        public ?int $beitragsartId = null,
        public bool $mitEhemaligen = false,
        public MitgliederSortierung $sortierung = MitgliederSortierung::Name,
        public bool $absteigend = false,
        public int $seite = 1,
        /** Members per page, one of ANZAHLEN; 0 = all on one page. */
        public int $anzahl = self::SEITENGROESSE,
    ) {
    }

    /**
     * @param array<string, mixed> $daten form fields and query values; the
     *        checkbox "ehemalige" is present ("1") when ticked
     */
    public static function aus(array $daten): self
    {
        $sortierung = self::text($daten, 'sortierung');
        $absteigend = str_ends_with($sortierung, self::ABSTEIGEND);

        return new self(
            text: self::text($daten, 'text'),
            abteilungId: self::id($daten, 'abteilung'),
            status: MitgliederStatus::tryFrom(self::text($daten, 'status')) ?? MitgliederStatus::Alle,
            beitragsartId: self::id($daten, 'beitragsart'),
            mitEhemaligen: self::text($daten, 'ehemalige') === '1',
            sortierung: MitgliederSortierung::tryFrom($absteigend ? substr($sortierung, 0, -strlen(self::ABSTEIGEND)) : $sortierung)
                ?? MitgliederSortierung::Name,
            absteigend: $absteigend,
            seite: max(1, self::id($daten, 'seite') ?? 1),
            anzahl: self::anzahl(self::text($daten, 'anzahl')),
        );
    }

    /** The value of the URL parameter and the field "anzahl". */
    public function anzahlWert(): string
    {
        return $this->anzahl === 0 ? self::ALLE : (string) $this->anzahl;
    }

    private static function anzahl(string $wert): int
    {
        if ($wert === self::ALLE) {
            return 0;
        }
        $zahl = preg_match('/^[1-9][0-9]{0,4}$/D', $wert) === 1 ? (int) $wert : self::SEITENGROESSE;

        return in_array($zahl, self::ANZAHLEN, true) ? $zahl : self::SEITENGROESSE;
    }

    /**
     * The words of the search text. Blanks and commas separate: "Muster,
     * Erika" finds Erika Mustermann like "erika muster".
     *
     * @return list<string>
     */
    public function woerter(): array
    {
        return preg_split('/[\s,]+/u', $this->text, flags: PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** True when something narrows the list (the sort order does not). */
    public function istGefiltert(): bool
    {
        return $this->woerter() !== [] || $this->abteilungId !== null || $this->beitragsartId !== null
            || $this->status !== MitgliederStatus::Alle || $this->mitEhemaligen;
    }

    /** The value of the URL parameter "sortierung". */
    public function sortierungWert(): string
    {
        return $this->sortierung->value . ($this->absteigend ? self::ABSTEIGEND : '');
    }

    /**
     * The sort value a click on the header of $spalte leads to: the column
     * ascending, or descending when it is the ascending one now.
     */
    public function sortierungNach(MitgliederSortierung $spalte): string
    {
        $wirdAbsteigend = $this->sortierung === $spalte && !$this->absteigend;

        return $spalte->value . ($wirdAbsteigend ? self::ABSTEIGEND : '');
    }

    /**
     * The values that differ from the default as URL parameters - WITHOUT
     * the search text: a name must not end up in a URL (CLAUDE.md section 4),
     * so the text only ever travels in the body of a POST.
     *
     * @return array<string, string>
     */
    public function parameter(): array
    {
        $parameter = [];
        if ($this->abteilungId !== null) {
            $parameter['abteilung'] = (string) $this->abteilungId;
        }
        if ($this->status !== MitgliederStatus::Alle) {
            $parameter['status'] = $this->status->value;
        }
        if ($this->beitragsartId !== null) {
            $parameter['beitragsart'] = (string) $this->beitragsartId;
        }
        if ($this->mitEhemaligen) {
            $parameter['ehemalige'] = '1';
        }
        if ($this->sortierungWert() !== MitgliederSortierung::Name->value) {
            $parameter['sortierung'] = $this->sortierungWert();
        }
        if ($this->seite > 1) {
            $parameter['seite'] = (string) $this->seite;
        }
        if ($this->anzahl !== self::SEITENGROESSE) {
            $parameter['anzahl'] = $this->anzahlWert();
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
    private static function id(array $daten, string $name): ?int
    {
        $wert = self::text($daten, $name);

        return preg_match('/^[1-9][0-9]{0,17}$/D', $wert) === 1 ? (int) $wert : null;
    }
}
