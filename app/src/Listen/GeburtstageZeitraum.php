<?php

declare(strict_types=1);

namespace App\Listen;

use App\Support\Alter;

/**
 * The period of the birthday list: the week (Monday to Sunday), the month or
 * the year around an anchor day, and its neighbours for "Zurück / Vor"
 * (docs/spec/09-listen-und-exporte.md section 2).
 */
final readonly class GeburtstageZeitraum
{
    public const array ARTEN = ['woche' => 'Woche', 'monat' => 'Monat', 'jahr' => 'Jahr'];

    private const array MONATE = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    private function __construct(
        public string $art,
        public \DateTimeImmutable $von,
        public \DateTimeImmutable $bis,
    ) {
    }

    /** @param string $art woche | monat | jahr; anything else is the month */
    public static function um(string $art, \DateTimeImmutable $anker): self
    {
        $anker = $anker->setTime(0, 0);

        return match ($art) {
            'woche' => new self('woche', $monatag = $anker->modify('monday this week'), $monatag->modify('+6 days')),
            'jahr' => new self('jahr', $anker->setDate((int) $anker->format('Y'), 1, 1), $anker->setDate((int) $anker->format('Y'), 12, 31)),
            default => new self('monat', $erster = $anker->modify('first day of this month'), $erster->modify('last day of this month')),
        };
    }

    /** The first day of the previous (-1) or next (+1) period. */
    public function nachbar(int $richtung): self
    {
        $anker = match ($this->art) {
            'woche' => $this->von->modify(sprintf('%+d week', $richtung)),
            'jahr' => $this->von->modify(sprintf('%+d year', $richtung)),
            default => $this->von->modify(sprintf('%+d month', $richtung)),
        };

        return self::um($this->art, $anker);
    }

    public function titel(): string
    {
        return match ($this->art) {
            'woche' => sprintf('%s bis %s', $this->von->format('d.m.Y'), $this->bis->format('d.m.Y')),
            'jahr' => $this->von->format('Y'),
            default => self::MONATE[(int) $this->von->format('n')] . ' ' . $this->von->format('Y'),
        };
    }

    /** @return list<int> the months with days in the period, for a coarse SQL filter */
    public function monate(): array
    {
        $monate = [];
        for ($tag = $this->von; $tag <= $this->bis; $tag = $tag->modify('+1 day')) {
            $monate[(int) $tag->format('n')] = true;
        }

        return array_keys($monate);
    }

    /**
     * The day this birthday falls on within the period (29 February counts
     * as 28 February in a year without one), or null when it is outside.
     * A week over New Year can hold birthdays of two years.
     */
    public function geburtstagIn(\DateTimeInterface $geburtsdatum): ?\DateTimeImmutable
    {
        for ($jahr = (int) $this->von->format('Y'); $jahr <= (int) $this->bis->format('Y'); $jahr++) {
            $tag = new \DateTimeImmutable(sprintf('%d-%s', $jahr, Alter::geburtstagImJahr($geburtsdatum, $jahr)), $this->von->getTimezone());
            if ($tag >= $this->von && $tag <= $this->bis) {
                return $tag;
            }
        }

        return null;
    }
}
