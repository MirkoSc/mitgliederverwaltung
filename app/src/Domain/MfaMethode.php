<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The second factor an account chose (docs/spec/02-sicherheit.md section 3),
 * stored in `user.mfa_method`; NULL there means "not set up yet" and the
 * login guard forces the setup. Taken over from Vereinsbelege's MfaMethod.
 *
 * The method decides what the login asks for: a mailed code or one from the
 * app. The backup codes work with either.
 */
enum MfaMethode: string
{
    /** Default and preselected: no app needed. */
    case Email = 'email';
    case App = 'totp';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Email => 'Code per E-Mail',
            self::App => 'Authenticator-App',
        };
    }
}
