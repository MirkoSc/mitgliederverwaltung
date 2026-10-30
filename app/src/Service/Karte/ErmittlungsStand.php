<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * Where the geocoding stands: how many addresses there are, how many were
 * found - exactly, approximately (issue #108), by hand (issue #108) or not
 * at all -, how many are still open, and what this call did. Counts only,
 * no address: the answer goes to the browser as JSON and to the cron
 * result.
 */
final readonly class ErmittlungsStand
{
    public function __construct(
        public int $gesamt,
        public int $gefunden,
        public int $nichtGefunden,
        public int $bearbeitet = 0,
        public bool $laeuftBereits = false,
        /** Set while the service rests after a failure: when the next try is due. */
        public ?\DateTimeImmutable $pauseBis = null,
        /** Short reason of the last failure, without data ("HTTP 429"). */
        public string $fehler = '',
        /** Only postcode and town were found (GeoStatus::Ungefaehr). */
        public int $ungefaehr = 0,
        /** Points set by hand at a member (GeoStatus::VonHand). */
        public int $vonHand = 0,
    ) {
    }

    /** A copy with what one call did on top of the counts. */
    public function mit(int $bearbeitet = 0, bool $laeuftBereits = false, ?\DateTimeImmutable $pauseBis = null, string $fehler = ''): self
    {
        return new self($this->gesamt, $this->gefunden, $this->nichtGefunden, $bearbeitet, $laeuftBereits, $pauseBis, $fehler, $this->ungefaehr, $this->vonHand);
    }

    public function offen(): int
    {
        return max(0, $this->gesamt - $this->gefunden - $this->ungefaehr - $this->vonHand - $this->nichtGefunden);
    }

    /** Nothing left this chain could do now: all asked, or the service rests. */
    public function fertig(): bool
    {
        return $this->offen() === 0 || $this->pauseBis !== null;
    }

    /**
     * The line for the page: "x von y Anschriften gefunden", then - only
     * when there are any - the approximate ones and the ones set by hand.
     */
    public function text(): string
    {
        $text = sprintf('%d von %d %s gefunden', $this->gefunden, $this->gesamt, $this->gesamt === 1 ? 'Anschrift' : 'Anschriften');
        if ($this->ungefaehr > 0) {
            $text .= sprintf(', %d ungefähr', $this->ungefaehr);
        }
        if ($this->vonHand > 0) {
            $text .= sprintf(', %d von Hand gesetzt', $this->vonHand);
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gesamt' => $this->gesamt,
            'gefunden' => $this->gefunden,
            'ungefaehr' => $this->ungefaehr,
            'von_hand' => $this->vonHand,
            'nicht_gefunden' => $this->nichtGefunden,
            'offen' => $this->offen(),
            'bearbeitet' => $this->bearbeitet,
            'laeuft_bereits' => $this->laeuftBereits,
            'pause_bis' => $this->pauseBis?->format('H:i'),
            'fertig' => $this->fertig(),
            'text' => $this->text(),
        ];
    }
}
