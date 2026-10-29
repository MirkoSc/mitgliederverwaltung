<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The account behind a session, as App\Http\LoginGuard looks it up on every
 * request (docs/spec/02-sicherheit.md section 4) - so a lock or a role
 * change takes effect on the next click, not at the next login.
 *
 * A user, not a member (02 section 5): nothing here is member data.
 */
final readonly class Konto
{
    public function __construct(
        public int $id,
        /** Display name for the user menu. */
        public string $name,
        public Rolle $rolle,
        /** Barrierefrei-Modus (docs/spec/06-oberflaeche.md section 4). */
        public bool $barrierefrei = false,
        /** `user.session_epoch`: a session with an older value has ended. */
        public int $sessionEpoch = 0,
        /**
         * Whether a second factor is set up (`user.mfa_method`). Until it
         * is, App\Http\LoginGuard lets the account reach nothing but the
         * setup page (02 section 3, "Erzwungene Einrichtung").
         */
        public bool $zweiterFaktor = true,
    ) {
    }

    public function darf(Permission $recht): bool
    {
        return $this->rolle->darf($recht);
    }
}
