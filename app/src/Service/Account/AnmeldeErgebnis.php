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
    ) {
    }

    public static function erfolg(Benutzer $benutzer): self
    {
        return new self($benutzer, null);
    }

    public static function fehler(AnmeldeFehler $fehler): self
    {
        return new self(null, $fehler);
    }

    /** @phpstan-assert-if-true !null $this->benutzer */
    public function istErfolg(): bool
    {
        return $this->benutzer !== null;
    }
}
