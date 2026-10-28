<?php

declare(strict_types=1);

namespace App\Service\Rechtstexte;

use App\Dienste;
use App\Domain\Rechtstext;
use App\Repository\SettingRepository;

/**
 * The text of Impressum and Datenschutz, kept in the `setting` table as
 * plain Markdown (docs/spec/03-datenhaltung.md section 2). Nothing is
 * pre-filled: the association enters its own legal texts, the application
 * invents none (CLAUDE.md "Es wird nichts Fachliches erfunden").
 */
final readonly class Rechtstexte
{
    /** Longer texts are refused with a message, not cut off. */
    public const int MAX_ZEICHEN = 20000;

    public function __construct(private SettingRepository $einstellungen)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d->einstellungen()));
    }

    /** The Markdown source; '' while nothing has been entered. */
    public function text(Rechtstext $art): string
    {
        return $this->einstellungen->text($art->einstellung());
    }

    /**
     * Stores the text as it was entered: line endings normalised, trailing
     * whitespace removed. The caller checks the length (MAX_ZEICHEN).
     */
    public function speichern(Rechtstext $art, string $text): void
    {
        $normal = trim(str_replace(["\r\n", "\r"], "\n", $text));

        $this->einstellungen->set($art->einstellung(), $normal);
    }

    public static function istZuLang(string $text): bool
    {
        return mb_strlen($text) > self::MAX_ZEICHEN;
    }
}
