<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Why a member version was written (docs/spec/07-mitglieder.md section 7).
 * Stored as text in `mitglied_version.anlass`; the restore carries the
 * number of the version it came from, so it is a value object and not an
 * enum.
 */
final readonly class VersionsAnlass
{
    private const string PRAEFIX_WIEDERHERGESTELLT = 'wiederhergestellt aus Version ';

    private function __construct(public string $text)
    {
    }

    public static function angelegt(): self
    {
        return new self('angelegt');
    }

    public static function geaendert(): self
    {
        return new self('geaendert');
    }

    public static function importiert(): self
    {
        return new self('importiert');
    }

    public static function abgleich(): self
    {
        return new self('abgleich');
    }

    public static function wiederhergestellt(int $nr): self
    {
        return new self(self::PRAEFIX_WIEDERHERGESTELLT . $nr);
    }

    /** As read from the database; unknown text is kept as it is. */
    public static function ausText(string $text): self
    {
        return new self($text);
    }

    /** The version a restore came from, else null. */
    public function wiederhergestelltAus(): ?int
    {
        return str_starts_with($this->text, self::PRAEFIX_WIEDERHERGESTELLT)
            && ctype_digit($nr = substr($this->text, strlen(self::PRAEFIX_WIEDERHERGESTELLT)))
            ? (int) $nr
            : null;
    }

    /** For the history tab. */
    public function bezeichnung(): string
    {
        $aus = $this->wiederhergestelltAus();
        if ($aus !== null) {
            return 'Wiederhergestellt aus Version ' . $aus;
        }

        return match ($this->text) {
            'angelegt' => 'Angelegt',
            'geaendert' => 'Geändert',
            'importiert' => 'Importiert',
            'abgleich' => 'Abgleich',
            default => $this->text,
        };
    }
}
