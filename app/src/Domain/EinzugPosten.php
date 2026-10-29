<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One transaction of a direct debit as the archive shows it
 * (docs/spec/08-beitraege.md sections 3 and 6): a copy taken when the file
 * was made, stored in `einzug.daten`, independent of the member - it stays
 * when the member or the fee is deleted.
 */
final readonly class EinzugPosten
{
    public function __construct(
        public int $beitragId,
        public int $mitgliedId,
        public int $mitgliedsnummer,
        /** "Mustermann, Erika" as it was when the file was made. */
        public string $name,
        public int $jahr,
        /** Integer cent. */
        public int $betragCent,
        public string $mandatsreferenz,
        /** The EndToEndId in the file - what a returned debit names. */
        public string $endToEndId,
    ) {
    }

    /**
     * @return array{beitrag_id: int, mitglied_id: int, mitgliedsnummer: int, name: string, jahr: int, betrag_cent: int, mandatsreferenz: string, end_to_end_id: string}
     */
    public function alsArray(): array
    {
        return [
            'beitrag_id' => $this->beitragId,
            'mitglied_id' => $this->mitgliedId,
            'mitgliedsnummer' => $this->mitgliedsnummer,
            'name' => $this->name,
            'jahr' => $this->jahr,
            'betrag_cent' => $this->betragCent,
            'mandatsreferenz' => $this->mandatsreferenz,
            'end_to_end_id' => $this->endToEndId,
        ];
    }

    /**
     * @param array<mixed> $daten one entry of `einzug.daten`; missing keys read as 0 / ''
     */
    public static function ausArray(array $daten): self
    {
        $zahl = static fn(string $schluessel): int => is_int($daten[$schluessel] ?? null) ? $daten[$schluessel] : 0;
        $text = static fn(string $schluessel): string => is_string($daten[$schluessel] ?? null) ? $daten[$schluessel] : '';

        return new self(
            beitragId: $zahl('beitrag_id'),
            mitgliedId: $zahl('mitglied_id'),
            mitgliedsnummer: $zahl('mitgliedsnummer'),
            name: $text('name'),
            jahr: $zahl('jahr'),
            betragCent: $zahl('betrag_cent'),
            mandatsreferenz: $text('mandatsreferenz'),
            endToEndId: $text('end_to_end_id'),
        );
    }
}
