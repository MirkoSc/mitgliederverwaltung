<?php

declare(strict_types=1);

namespace App\Listen;

use App\Support\Datum;

/**
 * One filter of a list. The page reads its value from the query string, the
 * export from the posted form - both through lesen(), so that a value is
 * always valid: an unknown option, a broken date or a year out of range
 * falls back to the default instead of failing.
 */
final readonly class ListenFilter
{
    private const int JAHR_MIN = 1900;

    private const int JAHR_MAX = 2200;

    /**
     * @param array<string, string> $optionen value => label, for Auswahl
     */
    private function __construct(
        public string $name,
        public string $label,
        public FilterArt $art,
        public array $optionen,
        public string|int|bool $standard,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1 || in_array($name, ['sortierung', 'richtung', 'seite', 'format', '_csrf'], true)) {
            throw new \InvalidArgumentException(sprintf('Filter name "%s" is not allowed.', $name));
        }
    }

    /**
     * @param array<string, string> $optionen value => label; the first is the
     *        default unless $standard names another
     */
    public static function auswahl(string $name, string $label, array $optionen, ?string $standard = null): self
    {
        if ($optionen === []) {
            throw new \InvalidArgumentException('A selection needs options.');
        }
        $schluessel = array_map(strval(...), array_keys($optionen));
        $standard ??= $schluessel[0];
        if (!in_array($standard, $schluessel, true)) {
            throw new \InvalidArgumentException('The default is not among the options.');
        }

        return new self($name, $label, FilterArt::Auswahl, $optionen, $standard);
    }

    public static function haken(string $name, string $label): self
    {
        return new self($name, $label, FilterArt::Haken, [], false);
    }

    public static function jahr(string $name, string $label, int $standard): self
    {
        return new self($name, $label, FilterArt::Jahr, [], $standard);
    }

    /** @param string $standard "Y-m-d", or '' for "not set" */
    public static function datum(string $name, string $label, string $standard = ''): self
    {
        return new self($name, $label, FilterArt::Datum, [], $standard);
    }

    /**
     * The valid value out of a query string or a posted form.
     *
     * @param array<string, mixed> $eingabe
     */
    public function lesen(array $eingabe): string|int|bool
    {
        $roh = $eingabe[$this->name] ?? null;
        if (!is_string($roh) && !is_int($roh)) {
            return $this->standard;
        }
        $roh = trim((string) $roh);

        return match ($this->art) {
            FilterArt::Auswahl => array_key_exists($roh, $this->optionen) ? $roh : $this->standard,
            FilterArt::Haken => in_array($roh, ['1', 'on', 'true'], true),
            FilterArt::Jahr => preg_match('/^\d{4}$/D', $roh) === 1
                && (int) $roh >= self::JAHR_MIN && (int) $roh <= self::JAHR_MAX ? (int) $roh : $this->standard,
            FilterArt::Datum => ($datum = Datum::ausEingabe($roh)) !== null ? Datum::fuerDatenbank($datum) : $this->standard,
        };
    }
}
