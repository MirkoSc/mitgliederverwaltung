<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The fee of one member for one year (docs/spec/08-beitraege.md section 2),
 * unique per member and year. The amount is a copy taken when it was
 * created; it may only be changed while the status is `offen`.
 */
final readonly class Jahresbeitrag
{
    use Kopierbar;

    /**
     * @param list<Vermerk> $vermerke oldest first
     */
    public function __construct(
        public int $mitgliedId,
        public int $jahr,
        /** Integer cent. */
        public int $betragCent,
        public JahresbeitragStatus $status = JahresbeitragStatus::Offen,
        public ?int $einzugId = null,
        /** How it was paid by hand; null when paid by direct debit or not paid. */
        public ?Zahlweise $zahlweise = null,
        public ?\DateTimeImmutable $bezahltAm = null,
        public array $vermerke = [],
        public ?int $id = null,
    ) {
    }

    public function betragAenderbar(): bool
    {
        return $this->status === JahresbeitragStatus::Offen;
    }

    public function mitVermerk(Vermerk $vermerk): self
    {
        return clone($this, ['vermerke' => [...$this->vermerke, $vermerk]]);
    }

    /*
     * The status transitions of 08 section 2. Each returns the changed copy
     * or throws BeitragNichtAenderbar when the current status does not allow
     * it - one place for the rules, used by the fees page (#29) and the
     * direct debit (#30) alike:
     *
     *   offen ──inEinzug──> im_einzug ──bezahltPerEinzug──> bezahlt
     *     ^  \                 │                                │
     *     │   bezahltVonHand ──┼───────────────────────────────>│
     *     ├──── ausEinzugZurueck (direct debit discarded)       │
     *     └──── ruecklastschrift (note required) <──────────────┘
     */

    /** New amount by hand, only while `offen`, with a note who/when. */
    public function mitBetrag(int $betragCent, Vermerk $vermerk): self
    {
        if (!$this->betragAenderbar()) {
            throw new BeitragNichtAenderbar('Der Betrag lässt sich nur ändern, solange der Beitrag offen ist.');
        }
        if ($betragCent <= 0) {
            throw new BeitragNichtAenderbar('Der Betrag muss größer als 0 € sein.');
        }

        return $this->mit(['betragCent' => $betragCent, 'vermerke' => [...$this->vermerke, $vermerk]]);
    }

    /** Taken into a direct debit (#30). */
    public function inEinzug(int $einzugId): self
    {
        $this->erwarte(JahresbeitragStatus::Offen, 'Nur offene Beiträge können in einen Einzug.');

        return $this->mit(['status' => JahresbeitragStatus::ImEinzug, 'einzugId' => $einzugId]);
    }

    /** The bank collected the direct debit: paid on its due date (#30). */
    public function bezahltPerEinzug(\DateTimeImmutable $faelligAm): self
    {
        $this->erwarte(JahresbeitragStatus::ImEinzug, 'Nur Beiträge im Einzug werden mit dem Einzug bezahlt.');

        return $this->mit(['status' => JahresbeitragStatus::Bezahlt, 'zahlweise' => null, 'bezahltAm' => $faelligAm]);
    }

    /** The direct debit was discarded (file never sent): back to `offen` (#30). */
    public function ausEinzugZurueck(?Vermerk $vermerk = null): self
    {
        $this->erwarte(JahresbeitragStatus::ImEinzug, 'Der Beitrag steckt in keinem Einzug.');

        return $this->mit([
            'status' => JahresbeitragStatus::Offen,
            'einzugId' => null,
            'vermerke' => $vermerk === null ? $this->vermerke : [...$this->vermerke, $vermerk],
        ]);
    }

    /** Paid by hand: cash, transfer or otherwise, on a day, with a note. */
    public function bezahltVonHand(Zahlweise $zahlweise, \DateTimeImmutable $am, Vermerk $vermerk): self
    {
        if ($this->status === JahresbeitragStatus::ImEinzug) {
            throw new BeitragNichtAenderbar('Der Beitrag steckt in einem Einzug. Bestätigen oder verwerfen Sie zuerst den Einzug.');
        }
        $this->erwarte(JahresbeitragStatus::Offen, 'Der Beitrag ist schon bezahlt.');

        return $this->mit([
            'status' => JahresbeitragStatus::Bezahlt,
            'zahlweise' => $zahlweise,
            'bezahltAm' => $am,
            'vermerke' => [...$this->vermerke, $vermerk],
        ]);
    }

    /**
     * Returned debit (or a payment noted by mistake): `bezahlt` back to
     * `offen`, the note is required. The fee can then be collected again or
     * paid by hand.
     */
    public function ruecklastschrift(Vermerk $vermerk): self
    {
        $this->erwarte(JahresbeitragStatus::Bezahlt, 'Nur ein bezahlter Beitrag kann zurück auf offen.');
        if (trim($vermerk->text) === '') {
            throw new BeitragNichtAenderbar('Bitte schreiben Sie in den Vermerk, warum der Beitrag wieder offen ist.');
        }

        return $this->mit([
            'status' => JahresbeitragStatus::Offen,
            'einzugId' => null,
            'zahlweise' => null,
            'bezahltAm' => null,
            'vermerke' => [...$this->vermerke, $vermerk],
        ]);
    }

    private function erwarte(JahresbeitragStatus $status, string $meldung): void
    {
        if ($this->status !== $status) {
            throw new BeitragNichtAenderbar($meldung);
        }
    }
}
