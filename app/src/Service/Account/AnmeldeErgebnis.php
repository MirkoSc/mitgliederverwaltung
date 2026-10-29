<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\Benutzer;

/**
 * The outcome of App\Service\Account\Anmeldung::versuch(): the account, or
 * a failure whose message gives nothing away.
 */
final readonly class AnmeldeErgebnis
{
    private function __construct(
        public ?Benutzer $benutzer,
        public ?AnmeldeFehler $fehler,
        /** The account a failed attempt was aimed at, if it exists - for the audit log only, never for the answer. */
        public ?int $versuchtVon = null,
    ) {
    }

    public static function erfolg(Benutzer $benutzer): self
    {
        return new self($benutzer, null);
    }

    public static function fehler(AnmeldeFehler $fehler, ?int $versuchtVon = null): self
    {
        return new self(null, $fehler, $versuchtVon);
    }

    /** @phpstan-assert-if-true !null $this->benutzer */
    public function istErfolg(): bool
    {
        return $this->benutzer !== null;
    }
}
