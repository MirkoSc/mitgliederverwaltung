<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * Why a login attempt did not succeed (taken over from Vereinsbelege's
 * LoginFailure).
 *
 * Deliberately only two cases: an unknown address, a wrong password, a
 * locked account and an invitation nobody answered all end up as
 * `Zugangsdaten` with the very same sentence (docs/spec/02-sicherheit.md
 * section 3, no user enumeration). "Too many attempts" is the exception: a
 * person who is locked out needs to know that waiting helps, and the answer
 * is the same for an address that does not exist.
 */
enum AnmeldeFehler
{
    case Zugangsdaten;
    case ZuVieleVersuche;

    public function meldung(): string
    {
        return match ($this) {
            self::Zugangsdaten => 'E-Mail-Adresse oder Passwort ist falsch.',
            self::ZuVieleVersuche => 'Zu viele Anmeldeversuche. Bitte warten Sie 15 Minuten und versuchen Sie es dann erneut.',
        };
    }
}
