<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * Login password hashing (docs/spec/02-sicherheit.md section 3):
 * `password_hash()` with PASSWORD_ARGON2ID, falling back to PASSWORD_BCRYPT
 * where the host's PHP was built without libargon2. Taken over from
 * Vereinsbelege (there it sits next to the vault's key derivation, which
 * this application does not have).
 *
 * One class, so that the installer, the invitation, the reset and the login
 * cannot drift apart on the algorithm.
 */
final class PasswordHasher
{
    /**
     * Valid hashes of values nobody knows, made with PHP's default cost for
     * each algorithm. verifyDummy() runs against one when no account was
     * found, so that an unknown address costs the same time as a wrong
     * password and the login does not answer the question "does this
     * account exist?" through its own latency (docs/spec/02-sicherheit.md
     * section 3, no user enumeration).
     *
     * Constants, not computed: under PHP-FPM no object outlives a request,
     * so a lazily computed dummy cost a password_hash() PLUS a
     * password_verify() on every unknown address - twice the work of a
     * wrong password, and measurable (a finding of Vereinsbelege's security
     * review). PasswordHasherTest fails once a PHP release raises a default
     * cost, so the constants cannot quietly fall behind real hashes.
     */
    public const string DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$TzNaRVlBOW5pMUNzdUhUaA$8qTg+4bP8tqDmb0HXdi2pQJVGiSefp2srUJE96uBmBk';
    public const string DUMMY_BCRYPT = '$2y$12$uwn.vkgvfbhAcckoPpV/3OqDqMg3grqePCRDxbvZX55AANhHJTE4C';

    public function hash(#[\SensitiveParameter] string $password): string
    {
        $hash = password_hash($password, self::algorithm());
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Das Passwort konnte nicht gehasht werden.');
        }

        return $hash;
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Burns the same work as verify() without an account to check against.
     * The result is always false and is meant to be discarded.
     */
    public function verifyDummy(#[\SensitiveParameter] string $password): bool
    {
        return password_verify($password, self::dummyHash());
    }

    /**
     * Whether the stored hash was made with weaker parameters than the ones
     * in force now. The login has the plaintext in hand at that moment, so
     * it is the one place where raising the defaults can be applied without
     * asking anybody to change their password.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /** The dummy of the algorithm hash() uses right now. */
    public static function dummyHash(): string
    {
        return self::algorithm() === \PASSWORD_BCRYPT ? self::DUMMY_BCRYPT : self::DUMMY_ARGON2ID;
    }

    private static function algorithm(): string
    {
        return \defined('PASSWORD_ARGON2ID') ? \PASSWORD_ARGON2ID : \PASSWORD_BCRYPT;
    }
}
