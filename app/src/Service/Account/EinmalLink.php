<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * The token of a one-time link sent by mail - password reset and invitation
 * (docs/spec/02-sicherheit.md section 3: 32 bytes, only the hash stored).
 *
 * The link carries 32 random bytes as 64 hex characters; `auth_token`
 * stores SHA-256 of them. A plain digest is enough here (Vereinsbelege used
 * an HMAC because its index key existed anyway): the input is 256 bits of
 * randomness, so there is nothing to guess and nothing to look up in a
 * table. A database reader cannot turn a row back into a working link.
 */
final class EinmalLink
{
    /** A fresh token for a link. Never stored, never logged. */
    public static function neu(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** What `auth_token.token_hash` holds (32 raw bytes). */
    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token, true);
    }

    /**
     * Whether a value has the shape neu() hands out - anything else is not
     * worth a database query.
     */
    public static function formGueltig(#[\SensitiveParameter] string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $token) === 1;
    }
}
