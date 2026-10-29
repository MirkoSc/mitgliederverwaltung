<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dienste;

/**
 * Password rule from docs/spec/02-sicherheit.md section 3: at least 12
 * characters, checked against the shipped list of common passwords, no
 * composition rules (a forced digit or symbol pushes people towards
 * predictable patterns instead of length). Taken over from Vereinsbelege.
 *
 * The list is app/data/haeufige-passwoerter.txt, one lowercase entry per
 * line: Vereinsbelege's own compilation (base words, keyboard walks, German
 * and English top picks with the suffixes people append), not a separately
 * licensed breach corpus.
 *
 * One rule, one place: invitation, reset, own password change and the
 * installer's first admin all go through it.
 */
final class PasswordPolicy
{
    public const int MIN_LAENGE = 12;

    /** @var array<string, true>|null lazy: only loaded once a password is checked */
    private ?array $haeufige = null;

    public function __construct(private readonly string $listenDatei)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d->paths->dataDir() . '/haeufige-passwoerter.txt'));
    }

    /**
     * @return list<string> German messages, empty when the password is acceptable
     */
    public function verstoesse(#[\SensitiveParameter] string $passwort): array
    {
        $fehler = [];
        if (mb_strlen($passwort) < self::MIN_LAENGE) {
            $fehler[] = sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', self::MIN_LAENGE);
        }
        if (isset($this->haeufige()[mb_strtolower($passwort)])) {
            $fehler[] = 'Dieses Passwort ist zu bekannt und leicht zu erraten. Bitte wählen Sie ein anderes.';
        }

        return $fehler;
    }

    /**
     * The checks every new password goes through: both fields the same,
     * then the rule above.
     *
     * @return list<string>
     */
    public function pruefeNeu(#[\SensitiveParameter] string $passwort, #[\SensitiveParameter] string $wiederholung): array
    {
        if (!hash_equals($passwort, $wiederholung)) {
            return ['Die beiden Passwörter stimmen nicht überein.'];
        }

        return $this->verstoesse($passwort);
    }

    /**
     * @return array<string, true>
     */
    private function haeufige(): array
    {
        if ($this->haeufige !== null) {
            return $this->haeufige;
        }

        // The list is a defence in depth, not the only rule: a missing file
        // (broken packaging) leaves the length rule standing instead of
        // failing every password change.
        $zeilen = is_file($this->listenDatei) ? file($this->listenDatei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

        return $this->haeufige = array_fill_keys($zeilen === false ? [] : array_map('trim', $zeilen), true);
    }
}
