<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One direct debit run (docs/spec/08-beitraege.md section 3). Never deleted,
 * not even with a member: `daten` keeps its own copy of the transactions and
 * the file that went to the bank is an encrypted blob that is never
 * regenerated.
 */
final readonly class Einzug
{
    use Kopierbar;

    /**
     * @param array<mixed> $daten transactions as shown in the archive
     */
    public function __construct(
        public EinzugStatus $status,
        public \DateTimeImmutable $faelligAm,
        public ?int $erstelltVon,
        public \DateTimeImmutable $erstelltAm,
        public int $anzahl,
        /** Integer cent. */
        public int $summeCent,
        /** pain.008 MsgId, max. 35 characters. */
        public string $msgId = '',
        /** The stored XML file (file_blob); null only while it is being created. */
        public ?int $dateiBlobId = null,
        public array $daten = [],
        public ?\DateTimeImmutable $bestaetigtAm = null,
        public ?int $bestaetigtVon = null,
        public ?int $id = null,
    ) {
    }

    /**
     * The transactions of `daten` (08 section 3, the archive), in the order
     * of the file.
     *
     * @return list<EinzugPosten>
     */
    public function posten(): array
    {
        return array_values(array_map(
            static fn(mixed $posten): EinzugPosten => EinzugPosten::ausArray(is_array($posten) ? $posten : []),
            $this->daten,
        ));
    }

    /** Collected (`bestaetigt`) or discarded (`verworfen`) - closed for good. */
    public function abgeschlossen(): bool
    {
        return $this->status !== EinzugStatus::Erzeugt;
    }
}
