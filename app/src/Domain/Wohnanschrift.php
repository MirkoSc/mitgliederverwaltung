<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Street, postcode, town and country of a member - what the families page
 * compares and copies (docs/spec/07-mitglieder.md section 3, issue #92).
 * Two addresses are the same when all four fields match without case and
 * surrounding blanks; "Anschrift übernehmen" copies all four, so "differs"
 * means "would change".
 */
final readonly class Wohnanschrift
{
    public function __construct(
        public string $strasse,
        public string $plz,
        public string $ort,
        public string $land,
    ) {
    }

    public static function von(Mitglied $mitglied): self
    {
        return new self($mitglied->strasse, $mitglied->plz, $mitglied->ort, $mitglied->land);
    }

    public function gleich(self $andere): bool
    {
        return $this->schluessel() === $andere->schluessel();
    }

    public function istLeer(): bool
    {
        return trim($this->strasse) === '' && trim($this->plz) === '' && trim($this->ort) === '';
    }

    /** One line: "Am Hang 1, 12345 Beispielstadt", the country only when it is not Germany. */
    public function text(): string
    {
        $ort = trim(trim($this->plz) . ' ' . trim($this->ort));
        $land = trim($this->land);
        $teile = array_filter(
            [trim($this->strasse), $ort, $land === '' || $land === Mitglied::LAND_DEFAULT ? '' : $land],
            static fn(string $teil): bool => $teil !== '',
        );

        return implode(', ', $teile);
    }

    /**
     * The fields as Mitglied::mit() takes them.
     *
     * @return array{strasse: string, plz: string, ort: string, land: string}
     */
    public function felder(): array
    {
        return ['strasse' => $this->strasse, 'plz' => $this->plz, 'ort' => $this->ort, 'land' => $this->land];
    }

    private function schluessel(): string
    {
        return implode('|', array_map(
            static fn(string $teil): string => mb_strtolower(trim($teil)),
            [$this->strasse, $this->plz, $this->ort, $this->land],
        ));
    }
}
