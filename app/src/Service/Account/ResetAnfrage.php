<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * What App\Service\Account\PasswortReset::anfordern() found out. The browser
 * gets the same sentence either way (no user enumeration,
 * docs/spec/02-sicherheit.md section 3) - only whether a mail goes out
 * depends on this.
 */
final readonly class ResetAnfrage
{
    private function __construct(
        public bool $zuVieleVersuche,
        /** Address to send the link to; null = no mail. */
        public ?string $email,
        /** Plain token for the link - never stored, never logged. */
        public ?string $token,
    ) {
    }

    public static function link(string $email, #[\SensitiveParameter] string $token): self
    {
        return new self(false, $email, $token);
    }

    public static function keinKonto(): self
    {
        return new self(false, null, null);
    }

    public static function gesperrt(): self
    {
        return new self(true, null, null);
    }

    /** @return array<string, bool|string|null> */
    public function __debugInfo(): array
    {
        return [
            'zuVieleVersuche' => $this->zuVieleVersuche,
            'email' => $this->email === null ? null : '***',
            'token' => $this->token === null ? null : '***',
        ];
    }
}
