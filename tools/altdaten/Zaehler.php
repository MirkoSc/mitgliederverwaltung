<?php

declare(strict_types=1);

namespace Tools\Altdaten;

/**
 * Counters for the conversion summary. Only counts and fixed labels go in
 * here - never a name, address or IBAN (the summary must be safe to paste
 * into an issue).
 */
final class Zaehler
{
    /** @var array<string, array<string, int>> */
    private array $werte = [];

    public function plus(string $gruppe, string $schluessel = ''): void
    {
        $this->werte[$gruppe][$schluessel] = ($this->werte[$gruppe][$schluessel] ?? 0) + 1;
    }

    public function summe(string $gruppe): int
    {
        return array_sum($this->werte[$gruppe] ?? []);
    }

    /** @return array<string, int> */
    public function gruppe(string $gruppe): array
    {
        $werte = $this->werte[$gruppe] ?? [];
        ksort($werte);

        return $werte;
    }
}
