<?php

declare(strict_types=1);

namespace App\Listen;

/**
 * One column of a list: shown in the view and exported with the same
 * title and type (docs/spec/09-listen-und-exporte.md section 1: "genau den
 * Spalten und Filtern der Ansicht").
 */
final readonly class Spalte
{
    /**
     * @param string $schluessel key in ListenZeile::$werte, also the sort parameter
     * @param bool $nebenspalte gives way first on a narrow screen and in
     *        the Barrierefrei-Modus (css class "nebenspalte")
     */
    public function __construct(
        public string $schluessel,
        public string $titel,
        public SpaltenTyp $typ = SpaltenTyp::Text,
        public bool $nebenspalte = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $schluessel) !== 1) {
            throw new \InvalidArgumentException(sprintf('Column key "%s" must be lowercase letters, digits and underscores.', $schluessel));
        }
    }

    public static function text(string $schluessel, string $titel, bool $nebenspalte = false): self
    {
        return new self($schluessel, $titel, SpaltenTyp::Text, $nebenspalte);
    }

    public static function datum(string $schluessel, string $titel, bool $nebenspalte = false): self
    {
        return new self($schluessel, $titel, SpaltenTyp::Datum, $nebenspalte);
    }

    public static function zahl(string $schluessel, string $titel, bool $nebenspalte = false): self
    {
        return new self($schluessel, $titel, SpaltenTyp::Zahl, $nebenspalte);
    }

    public static function geld(string $schluessel, string $titel, bool $nebenspalte = false): self
    {
        return new self($schluessel, $titel, SpaltenTyp::Geld, $nebenspalte);
    }
}
