<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dienste;
use App\Domain\Benutzer;
use App\Domain\BenutzerStatus;
use App\Domain\Rolle;
use App\Repository\AuthTokenRepository;
use App\Repository\BenutzerRepository;

/**
 * What the admin does with existing accounts (docs/spec/02-sicherheit.md
 * section 5): change the role, lock, unlock, delete. Inviting is
 * App\Service\Account\Einladung.
 *
 * Two rules keep the installation reachable:
 *   - an admin never locks, deletes or demotes the own account (the one
 *     click that would lock the person out who is clicking);
 *   - the last active admin stays an active admin.
 *
 * Locking ends every session of the account at once (`session_epoch`; the
 * login guard also refuses a locked account on its own) and drops its open
 * links.
 */
final readonly class BenutzerVerwaltung
{
    public function __construct(
        private \PDO $pdo,
        private PasswordPolicy $policy,
        private PasswordHasher $passwoerter = new PasswordHasher(),
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d->pdo(), PasswordPolicy::aus($d)));
    }

    /**
     * @throws BenutzerRegelVerstoss
     */
    public function rolleAendern(int $adminId, int $userId, Rolle $rolle): Benutzer
    {
        $benutzer = $this->finde($userId);
        if ($benutzer->rolle === $rolle) {
            return $benutzer;
        }
        if ($userId === $adminId) {
            throw new BenutzerRegelVerstoss('Die eigene Rolle können Sie nicht ändern. Das muss ein anderer Admin tun.');
        }
        $this->imSchutz($benutzer, fn() => $this->repo()->updateRolle($userId, $rolle));

        return $benutzer;
    }

    /**
     * @throws BenutzerRegelVerstoss
     */
    public function sperren(int $adminId, int $userId): Benutzer
    {
        $benutzer = $this->finde($userId);
        if ($userId === $adminId) {
            throw new BenutzerRegelVerstoss('Das eigene Konto können Sie nicht sperren.');
        }
        if ($benutzer->status !== BenutzerStatus::Aktiv) {
            throw new BenutzerRegelVerstoss('Nur ein aktives Konto lässt sich sperren. Eine offene Einladung löschen Sie stattdessen.');
        }
        $this->imSchutz($benutzer, function () use ($userId): void {
            $this->repo()->updateStatus($userId, BenutzerStatus::Gesperrt);
            $this->repo()->bumpSessionEpoch($userId);
            new AuthTokenRepository($this->pdo)->deleteForUser($userId);
        });

        return $benutzer;
    }

    /**
     * @throws BenutzerRegelVerstoss
     */
    public function entsperren(int $userId): Benutzer
    {
        $benutzer = $this->finde($userId);
        if ($benutzer->status !== BenutzerStatus::Gesperrt) {
            throw new BenutzerRegelVerstoss('Dieses Konto ist nicht gesperrt.');
        }
        $this->repo()->updateStatus($userId, BenutzerStatus::Aktiv);

        return $benutzer;
    }

    /**
     * @throws BenutzerRegelVerstoss
     */
    /**
     * Switches the Barrierefrei-Modus of an account for it (issue #87) - the
     * same setting the account switches itself in the user menu. Takes
     * effect with its next request (App\Http\LoginGuard reads the account
     * anew each time).
     *
     * @throws BenutzerRegelVerstoss
     */
    public function barrierefreiSetzen(int $userId, bool $an): Benutzer
    {
        $benutzer = $this->finde($userId);
        $this->repo()->updateBarrierefrei($userId, $an);

        return $benutzer;
    }

    public function loeschen(int $adminId, int $userId): Benutzer
    {
        $benutzer = $this->finde($userId);
        if ($userId === $adminId) {
            throw new BenutzerRegelVerstoss('Das eigene Konto können Sie nicht löschen.');
        }
        // auth_token goes with it (ON DELETE CASCADE).
        $this->imSchutz($benutzer, fn() => $this->repo()->delete($userId));

        return $benutzer;
    }

    /**
     * The very first account, active at once and admin - for the installer
     * (issue #3) and the development helper tools/benutzer-anlegen.php.
     * Same password rule as everywhere else.
     *
     * @return int the new account's id
     * @throws BenutzerRegelVerstoss
     */
    public function ersterAdmin(
        string $email,
        string $name,
        #[\SensitiveParameter] string $passwort,
        #[\SensitiveParameter] string $wiederholung,
        ?\DateTimeImmutable $now = null,
    ): int {
        $email = Einladung::pruefeEmail($email);
        $name = Einladung::pruefeName($name);
        $fehler = $this->policy->pruefeNeu($passwort, $wiederholung);
        if ($fehler !== []) {
            throw new BenutzerRegelVerstoss(implode(' ', $fehler));
        }
        if ($this->repo()->findByEmail($email) !== null) {
            throw new BenutzerRegelVerstoss('Für diese E-Mail-Adresse gibt es bereits einen Benutzer.');
        }

        return $this->repo()->insert(
            $email,
            $name,
            $this->passwoerter->hash($passwort),
            BenutzerStatus::Aktiv,
            Rolle::Admin,
            $now ?? new \DateTimeImmutable(),
        );
    }

    /**
     * @throws BenutzerRegelVerstoss
     */
    private function finde(int $userId): Benutzer
    {
        return $this->repo()->findById($userId)
            ?? throw new BenutzerRegelVerstoss('Diesen Benutzer gibt es nicht mehr.');
    }

    /**
     * Runs a step that may take an active admin away, in one transaction
     * with the "last active admin" check: the rows of the active admins are
     * locked first (FOR UPDATE), so two admins demoting each other at the
     * same moment cannot both pass the check - the second waits and then
     * counts again.
     *
     * @param \Closure(): void $schritt
     * @throws BenutzerRegelVerstoss
     */
    private function imSchutz(Benutzer $benutzer, \Closure $schritt): void
    {
        $this->pdo->beginTransaction();
        try {
            $aktiveAdmins = $this->repo()->sperreAktiveAdmins();
            $istAktiverAdmin = in_array($benutzer->id, $aktiveAdmins, true);
            if ($istAktiverAdmin && count($aktiveAdmins) === 1) {
                throw new BenutzerRegelVerstoss('Das ist der letzte aktive Admin. Ernennen Sie zuerst einen anderen Benutzer zum Admin.');
            }
            $schritt();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function repo(): BenutzerRepository
    {
        return new BenutzerRepository($this->pdo);
    }
}
