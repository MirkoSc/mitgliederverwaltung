<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BenutzerStatus;
use App\Domain\Rolle;
use App\Repository\BenutzerRepository;
use App\Repository\RateLimitRepository;
use App\Service\Account\AnmeldeFehler;
use App\Service\Account\Anmeldung;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/**
 * The login (docs/spec/02-sicherheit.md section 3, "Pflicht-Tests": every
 * failure word for word the same, rate limit atomic).
 */
final class AnmeldungTest extends DatabaseTestCase
{
    private Anmeldung $anmeldung;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->anmeldung = new Anmeldung(new BenutzerRepository($this->pdo()), new RateLimiter(new RateLimitRepository($this->pdo())));
    }

    public function testTheRightPasswordLogsIn(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        $ergebnis = $this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '198.51.100.7');

        self::assertTrue($ergebnis->istErfolg());
        self::assertSame($id, $ergebnis->benutzer?->id);
    }

    /** One spelling of an address: case and surrounding spaces do not matter. */
    public function testTheAddressIsNormalised(): void
    {
        Benutzerdaten::anlegen($this->pdo());

        self::assertTrue($this->anmeldung->versuch('  Erika@Example.ORG ', Benutzerdaten::PASSWORT, '198.51.100.7')->istErfolg());
    }

    /**
     * Unknown address, wrong password, locked account, unanswered
     * invitation: one failure, one sentence (no user enumeration).
     */
    public function testEveryFailureIsWordForWordTheSame(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        Benutzerdaten::anlegen($this->pdo(), 'gesperrt@example.org', status: BenutzerStatus::Gesperrt);
        Benutzerdaten::anlegen($this->pdo(), 'eingeladen@example.org', status: BenutzerStatus::Eingeladen);

        $faelle = [
            'unknown address' => ['niemand@example.org', Benutzerdaten::PASSWORT],
            'wrong password' => ['erika@example.org', 'falsches-passwort-123'],
            'locked account' => ['gesperrt@example.org', Benutzerdaten::PASSWORT],
            'open invitation, empty password' => ['eingeladen@example.org', ''],
            'open invitation' => ['eingeladen@example.org', Benutzerdaten::PASSWORT],
            'empty address' => ['', Benutzerdaten::PASSWORT],
        ];

        $saetze = [];
        foreach ($faelle as $fall => [$email, $passwort]) {
            $ergebnis = $this->anmeldung->versuch($email, $passwort, '198.51.100.' . count($saetze));
            self::assertFalse($ergebnis->istErfolg(), $fall);
            self::assertSame(AnmeldeFehler::Zugangsdaten, $ergebnis->fehler, $fall);
            $saetze[] = $ergebnis->fehler->meldung();
        }

        self::assertCount(1, array_unique($saetze));
        self::assertSame('E-Mail-Adresse oder Passwort ist falsch.', $saetze[0]);
    }

    public function testTheAccountLimitHoldsEvenWithTheRightPassword(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');

        for ($i = 0; $i < RateLimiter::LOGIN_JE_KONTO; $i++) {
            // A different IP each time: the account counter alone stops it.
            $this->anmeldung->versuch('erika@example.org', 'falsch-falsch-falsch', '203.0.113.' . $i, $jetzt);
        }
        $ergebnis = $this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '203.0.113.200', $jetzt);

        self::assertSame(AnmeldeFehler::ZuVieleVersuche, $ergebnis->fehler);
        self::assertTrue(
            $this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '203.0.113.200', $jetzt->modify('+15 minutes'))->istErfolg(),
            'after the window the door opens again',
        );
    }

    public function testTheIpLimitHoldsAcrossAccounts(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');

        for ($i = 0; $i < RateLimiter::LOGIN_JE_IP; $i++) {
            $this->anmeldung->versuch('person' . $i . '@example.org', 'egal-egal-egal', '198.51.100.7', $jetzt);
        }

        self::assertSame(AnmeldeFehler::ZuVieleVersuche, $this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '198.51.100.7', $jetzt)->fehler);
    }

    /** The "too many" answer is the same for an address that does not exist. */
    public function testTheLimitAnswersTheSameForUnknownAddresses(): void
    {
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');
        for ($i = 0; $i <= RateLimiter::LOGIN_JE_KONTO; $i++) {
            $ergebnis = $this->anmeldung->versuch('niemand@example.org', 'egal-egal-egal', '203.0.113.' . $i, $jetzt);
        }

        self::assertSame(AnmeldeFehler::ZuVieleVersuche, $ergebnis->fehler);
    }

    /** A success clears the account's counter: only its failures stay counted. */
    public function testASuccessClearsTheAccountCounter(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');
        for ($i = 0; $i < RateLimiter::LOGIN_JE_KONTO - 1; $i++) {
            $this->anmeldung->versuch('erika@example.org', 'falsch-falsch-falsch', '203.0.113.' . $i, $jetzt);
        }

        self::assertTrue($this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '198.51.100.7', $jetzt)->istErfolg());
        for ($i = 0; $i < RateLimiter::LOGIN_JE_KONTO - 1; $i++) {
            $this->anmeldung->versuch('erika@example.org', 'falsch-falsch-falsch', '203.0.113.' . (50 + $i), $jetzt);
        }
        self::assertTrue($this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '198.51.100.8', $jetzt)->istErfolg());
    }

    /**
     * ... but not the IP's: whoever has one working account must not be
     * able to wipe the limit of their address between rounds of guessing.
     */
    public function testASuccessDoesNotClearTheIpCounter(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');
        for ($i = 0; $i < RateLimiter::LOGIN_JE_IP - 2; $i++) {
            $this->anmeldung->versuch('person' . $i . '@example.org', 'egal-egal-egal', '198.51.100.7', $jetzt);
        }
        self::assertTrue($this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '198.51.100.7', $jetzt)->istErfolg());

        $this->anmeldung->versuch('noch-wer@example.org', 'egal-egal-egal', '198.51.100.7', $jetzt);

        self::assertSame(AnmeldeFehler::ZuVieleVersuche, $this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '198.51.100.7', $jetzt)->fehler);
    }

    public function testAWeakerOldHashIsLiftedAtLogin(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $repo = new BenutzerRepository($this->pdo());
        $repo->updatePasswordHash($id, password_hash(Benutzerdaten::PASSWORT, PASSWORD_BCRYPT, ['cost' => 4]));

        self::assertTrue($this->anmeldung->versuch('erika@example.org', Benutzerdaten::PASSWORT, '198.51.100.7')->istErfolg());

        $neu = $repo->findById($id)?->passwordHash ?? '';
        self::assertFalse(password_needs_rehash($neu, \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT));
        self::assertTrue(password_verify(Benutzerdaten::PASSWORT, $neu));
    }

    public function testFinishingTheLoginRecordsTheTime(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo(), rolle: Rolle::Admin);

        $this->anmeldung->abgeschlossen($id, new \DateTimeImmutable('2026-03-02 10:00:00'));

        self::assertSame('2026-03-02 10:00', new BenutzerRepository($this->pdo())->findById($id)?->lastLoginAt?->format('Y-m-d H:i'));
    }
}
