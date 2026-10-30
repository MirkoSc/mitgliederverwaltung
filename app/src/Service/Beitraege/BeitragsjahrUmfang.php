<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

use App\Domain\Einzug;
use App\Support\Geld;

/**
 * What "Beiträge <Jahr> löschen" takes away (docs/spec/08-beitraege.md
 * section 2a, #90): the year's fees in figures and the direct debits that
 * belong to that year only. A run that also holds fees of another year
 * stays - its other fees still point to it.
 */
final readonly class BeitragsjahrUmfang
{
    /**
     * @param list<Einzug> $einzuege runs deleted with the year
     * @param list<Einzug> $gemischt runs that also hold other years - they stay
     */
    public function __construct(
        public int $jahr,
        public int $anzahl,
        public int $bezahlt,
        /** Integer cent. */
        public int $summeCent,
        public array $einzuege = [],
        public array $gemischt = [],
    ) {
    }

    public function leer(): bool
    {
        return $this->anzahl === 0 && $this->einzuege === [];
    }

    /** Stored direct debit files that go with the runs. */
    public function dateien(): int
    {
        return count(array_filter($this->einzuege, static fn(Einzug $e): bool => $e->dateiBlobId !== null));
    }

    /**
     * Whether a direct debit file with fees of this year exists - then the
     * bank may already have it, and the question says so (#90).
     */
    public function hatLastschriftdatei(): bool
    {
        foreach ([...$this->einzuege, ...$this->gemischt] as $einzug) {
            if ($einzug->dateiBlobId !== null) {
                return true;
            }
        }

        return false;
    }

    /** The message after deleting, German. */
    public function meldung(): string
    {
        $text = sprintf(
            'Die Beiträge %d sind gelöscht: %s (davon %d bezahlt, zusammen %s)',
            $this->jahr,
            $this->anzahl === 1 ? '1 Jahresbeitrag' : $this->anzahl . ' Jahresbeiträge',
            $this->bezahlt,
            Geld::anzeigen($this->summeCent),
        );
        $einzuege = count($this->einzuege);
        if ($einzuege > 0) {
            $text .= sprintf(' und %s mit %s', $einzuege === 1 ? '1 Einzug' : $einzuege . ' Einzüge', $this->dateien() === 1 ? '1 Lastschriftdatei' : $this->dateien() . ' Lastschriftdateien');
        }

        return $text . sprintf('. Sie können die Beiträge für %d jetzt neu erzeugen.', $this->jahr);
    }

    /**
     * For the audit log: figures only, no member data (02 section 8).
     *
     * @return array{jahr: int, beitraege: int, bezahlt: int, summe_cent: int, einzuege: list<int>, dateien: int}
     */
    public function protokoll(): array
    {
        return [
            'jahr' => $this->jahr,
            'beitraege' => $this->anzahl,
            'bezahlt' => $this->bezahlt,
            'summe_cent' => $this->summeCent,
            'einzuege' => array_values(array_map(static fn(Einzug $e): int => (int) $e->id, $this->einzuege)),
            'dateien' => $this->dateien(),
        ];
    }
}
