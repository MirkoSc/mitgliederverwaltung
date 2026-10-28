<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Konto;

/**
 * Where App\Http\LoginGuard looks up the account behind a session - on every
 * request, so locking an account, a role change or a deleted user take
 * effect at once (docs/spec/02-sicherheit.md section 4).
 *
 * Until the user table exists (issue #6), App\Http\Entwicklungskonten is the
 * only implementation; App\Dienste::kontoquelle() is the one place that
 * picks it.
 */
interface Kontoquelle
{
    /**
     * The account, or null when it does not exist (anymore) or may not log
     * in (locked, still invited).
     */
    public function konto(int $id): ?Konto;
}
