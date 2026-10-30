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
 * Inviting an account (docs/spec/02-sicherheit.md section 5). Taken over
 * from Vereinsbelege, without key pair and vault grant:
 *
 *   admin enters name, address, role (and whether the account starts in
 *   the Barrierefrei-Modus, issue #87) -> invitation mail (link, 72 h)
 *   -> the invited person sets a password -> account active.
 *
 * The row exists from the invitation on, with status `eingeladen` and no
 * password hash - the login refuses it like a locked one. The second factor
 * is set up at the first login (issue #8).
 *
 * Nothing about HTTP or mail here - the controller sends the link.
 */
final readonly class Einladung
{
    public const string TYP = 'invite';

    public const int GUELTIG_SEKUNDEN = 72 * 3600;

    public const int NAME_MAX = 100;

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
     * Creates the invited account and issues its link. $barrierefrei: the
     * account is in the Barrierefrei-Modus from its first login on (it can
     * switch it off itself, 06 section 4).
     *
     * @return array{userId: int, token: string, email: string}
     * @throws BenutzerRegelVerstoss
     */
    public function einladen(string $email, string $name, Rolle $rolle, ?\DateTimeImmutable $now = null, bool $barrierefrei = false): array
    {
        $now ??= new \DateTimeImmutable();
        $email = self::pruefeEmail($email);
        $name = self::pruefeName($name);

        if ($this->benutzer()->findByEmail($email) !== null) {
            // The admin can see every account in the list anyway - saying
            // so enumerates nothing.
            throw new BenutzerRegelVerstoss('Für diese E-Mail-Adresse gibt es bereits einen Benutzer.');
        }

        try {
            $userId = $this->benutzer()->insert($email, $name, '', BenutzerStatus::Eingeladen, $rolle, $now, $barrierefrei);
        } catch (\PDOException $e) {
            // Two admins inviting the same address at the same moment: the
            // UNIQUE key decides.
            if ($e->getCode() === '23000') {
                throw new BenutzerRegelVerstoss('Für diese E-Mail-Adresse gibt es bereits einen Benutzer.');
            }
            throw $e;
        }

        return ['userId' => $userId, 'token' => $this->neuerLink($userId, $now), 'email' => $email];
    }

    /**
     * A new link for an invitation nobody answered (lost mail, 72 hours
     * over). Replaces the old link instead of opening a second door.
     *
     * @return array{token: string, email: string}
     * @throws BenutzerRegelVerstoss
     */
    public function erneutSenden(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $benutzer = $this->benutzer()->findById($userId);
        if ($benutzer === null || $benutzer->status !== BenutzerStatus::Eingeladen) {
            throw new BenutzerRegelVerstoss('Dieser Benutzer wartet nicht auf eine Einladung.');
        }

        return ['token' => $this->neuerLink($userId, $now ?? new \DateTimeImmutable()), 'email' => $benutzer->email];
    }

    /** Whether a link is still good - for showing the form. Does not spend it. */
    public function nutzbar(#[\SensitiveParameter] string $token, ?\DateTimeImmutable $now = null): bool
    {
        return $this->finde($token, $now ?? new \DateTimeImmutable()) !== null;
    }

    /**
     * Sets the password and activates the account. The link is spent only
     * once the password passes the rule.
     */
    public function annehmen(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $passwort,
        #[\SensitiveParameter] string $wiederholung,
        ?\DateTimeImmutable $now = null,
    ): PasswortErgebnis {
        $now ??= new \DateTimeImmutable();
        $gefunden = $this->finde($token, $now);
        if ($gefunden === null) {
            return PasswortErgebnis::fehler(self::linkUngueltig());
        }

        $fehler = $this->policy->pruefeNeu($passwort, $wiederholung);
        if ($fehler !== []) {
            return PasswortErgebnis::fehler(...$fehler);
        }

        [$tokenId, $benutzer] = $gefunden;
        $hash = $this->passwoerter->hash($passwort);

        $this->pdo->beginTransaction();
        try {
            if (!$this->tokens()->markUsed($tokenId, $now)) {
                $this->pdo->rollBack();

                return PasswortErgebnis::fehler(self::linkUngueltig());
            }
            $this->benutzer()->updatePasswordHash($benutzer->id, $hash);
            $this->benutzer()->updateStatus($benutzer->id, BenutzerStatus::Aktiv);
            $this->tokens()->deleteForUser($benutzer->id, self::TYP);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return PasswortErgebnis::erfolg($benutzer->id, $benutzer->sessionEpoch);
    }

    /** Until when the open invitation of an account is good; null = none or run out. */
    public function gueltigBis(int $userId, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        return $this->tokens()->gueltigBis($userId, self::TYP, $now ?? new \DateTimeImmutable());
    }

    public static function linkUngueltig(): string
    {
        return 'Dieser Einladungslink ist ungültig oder abgelaufen. Bitten Sie den Admin des Vereins um eine neue Einladung.';
    }

    /**
     * A plausible address that fits `user.email` (255 bytes, RFC 5321),
     * normalised.
     *
     * @throws BenutzerRegelVerstoss
     */
    public static function pruefeEmail(string $email): string
    {
        $email = Benutzer::normalisiereEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new BenutzerRegelVerstoss('Bitte eine gültige E-Mail-Adresse angeben.');
        }

        return $email;
    }

    /**
     * @throws BenutzerRegelVerstoss
     */
    public static function pruefeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new BenutzerRegelVerstoss('Bitte einen Namen angeben.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new BenutzerRegelVerstoss(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }

        return $name;
    }

    private function neuerLink(int $userId, \DateTimeImmutable $now): string
    {
        $token = EinmalLink::neu();
        $this->tokens()->deleteForUser($userId, self::TYP);
        $this->tokens()->insert($userId, self::TYP, EinmalLink::hash($token), $now->modify('+' . self::GUELTIG_SEKUNDEN . ' seconds'), $now);

        return $token;
    }

    /**
     * The token row and its account - only while the account is still
     * invited.
     *
     * @return array{0: int, 1: Benutzer}|null
     */
    private function finde(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): ?array
    {
        if (!EinmalLink::formGueltig($token)) {
            return null;
        }
        $zeile = $this->tokens()->findUsable(self::TYP, EinmalLink::hash($token), $now);
        if ($zeile === null) {
            return null;
        }
        $benutzer = $this->benutzer()->findById($zeile['user_id']);

        return $benutzer !== null && $benutzer->status === BenutzerStatus::Eingeladen ? [$zeile['id'], $benutzer] : null;
    }

    private function benutzer(): BenutzerRepository
    {
        return new BenutzerRepository($this->pdo);
    }

    private function tokens(): AuthTokenRepository
    {
        return new AuthTokenRepository($this->pdo);
    }
}
