<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Konto;
use App\Domain\Rolle;

/**
 * Two made-up accounts for looking at the pages before the real login
 * exists (issue #1 until issue #6): one per role, chosen on the placeholder
 * login page.
 *
 * Doubly fenced, so it can never open a real installation: only with
 * `debug` in config.php (which the installer never writes) AND a
 * development version ("…-dev", which a release build never carries).
 * Issue #6 replaces this class with the user table and deletes it.
 */
final readonly class Entwicklungskonten implements Kontoquelle
{
    public const int ADMIN = 1;
    public const int VORSTAND = 2;

    public function __construct(private bool $aktiv)
    {
    }

    public function aktiv(): bool
    {
        return $this->aktiv;
    }

    public function konto(int $id): ?Konto
    {
        if (!$this->aktiv) {
            return null;
        }

        return match ($id) {
            self::ADMIN => new Konto(self::ADMIN, 'Erika Beispiel', Rolle::Admin),
            self::VORSTAND => new Konto(self::VORSTAND, 'Volker Muster', Rolle::Vorstand),
            default => null,
        };
    }
}
