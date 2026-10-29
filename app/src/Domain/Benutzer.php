<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `user` (docs/spec/03-datenhaltung.md section 2): an account of
 * the application, not a member (docs/spec/02-sicherheit.md section 5).
 *
 * App\Domain\Konto is the slice of it the login guard needs on every
 * request; this is the whole row, for the login itself and the user
 * administration.
 */
final readonly class Benutzer
{
    public function __construct(
        public int $id,
        /** Normalised (normalisiereEmail()). */
        public string $email,
        public string $name,
        /** Empty while an invitation is not answered. */
        public string $passwordHash,
        public BenutzerStatus $status,
        public Rolle $rolle,
        public bool $barrierefrei,
        /** Second factor (issue #8); null = not set up. */
        public ?string $mfaMethod,
        public int $sessionEpoch,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastLoginAt,
    ) {
    }

    /** Only an active account logs in; invited and locked ones do not. */
    public function darfAnmelden(): bool
    {
        return $this->status === BenutzerStatus::Aktiv;
    }

    public function konto(): Konto
    {
        return new Konto($this->id, $this->name, $this->rolle, $this->barrierefrei, $this->sessionEpoch);
    }

    /**
     * The one spelling an address is stored and looked up in: trimmed and
     * lowercased - "Erika@Example.org" and "erika@example.org" are one
     * account. Lowercasing the local part is not strictly RFC 5321, but no
     * mail provider a club uses distinguishes it.
     */
    public static function normalisiereEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array<string, mixed> without the password hash
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'rolle' => $this->rolle->value,
            'passwordHash' => '***',
        ];
    }
}
