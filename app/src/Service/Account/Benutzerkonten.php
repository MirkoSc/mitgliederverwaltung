<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Domain\Konto;
use App\Http\Kontoquelle;
use App\Repository\BenutzerRepository;

/**
 * The accounts behind the sessions: the `user` table, read on every
 * protected request (App\Http\LoginGuard) - so a lock, a role change or a
 * deleted account take effect on the next click (docs/spec/02-sicherheit.md
 * section 4).
 *
 * The connection is asked for only when a session actually names an
 * account: the login page and an anonymous visitor open none.
 */
final readonly class Benutzerkonten implements Kontoquelle
{
    /**
     * @param \Closure(): \PDO $pdo
     */
    public function __construct(private \Closure $pdo)
    {
    }

    public function konto(int $id): ?Konto
    {
        $benutzer = new BenutzerRepository(($this->pdo)())->findById($id);

        return $benutzer !== null && $benutzer->darfAnmelden() ? $benutzer->konto() : null;
    }
}
