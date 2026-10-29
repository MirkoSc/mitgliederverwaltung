<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BenutzerStatus;
use App\Repository\BenutzerRepository;
use App\Repository\RateLimitRepository;
use App\Service\Account\EinmalLink;
use App\Service\Account\PasswordPolicy;
use App\Service\Account\PasswortReset;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/**
 * "Passwort vergessen" (docs/spec/02-sicherheit.md section 3, "Pflicht-
 * Tests": token one use, sessions ended).
 */
final class PasswortResetTest extends DatabaseTestCase
{
    private const string NEU = 'neue-pferde-springen-weiter';

    private PasswortReset $reset;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->reset = new PasswortReset(
            $this->pdo(),
            new RateLimiter(new RateLimitRepository($this->pdo())),
            new PasswordPolicy(dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt'),
        );
    }

    public function testTheLinkSetsANewPasswordAndEndsEverySession(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $vorher = new BenutzerRepository($this->pdo())->findById($id)?->sessionEpoch;

        $anfrage = $this->reset->anfordern('Erika@example.org', '198.51.100.7');
        self::assertSame('erika@example.org', $anfrage->email);
        self::assertNotNull($anfrage->token);
        self::assertTrue($this->reset->nutzbar($anfrage->token));

        $ergebnis = $this->reset->abschliessen($anfrage->token, self::NEU, self::NEU);

        self::assertTrue($ergebnis->istErfolg());
        $benutzer = new BenutzerRepository($this->pdo())->findById($id);
        self::assertTrue(password_verify(self::NEU, $benutzer?->passwordHash ?? ''));
        self::assertSame(($vorher ?? 0) + 1, $benutzer?->sessionEpoch, 'session_epoch moved on: every session is over');
        self::assertSame($benutzer?->sessionEpoch, $ergebnis->sessionEpoch);
    }

    public function testTheLinkWorksExactlyOnce(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $token = (string) $this->reset->anfordern('erika@example.org', '198.51.100.7')->token;

        self::assertTrue($this->reset->abschliessen($token, self::NEU, self::NEU)->istErfolg());

        self::assertFalse($this->reset->nutzbar($token));
        self::assertSame([PasswortReset::linkUngueltig()], $this->reset->abschliessen($token, 'noch-ein-anderes-passwort', 'noch-ein-anderes-passwort')->fehler);
    }

    /** A password the rule refuses must not cost the user the link. */
    public function testARefusedPasswordKeepsTheLink(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $token = (string) $this->reset->anfordern('erika@example.org', '198.51.100.7')->token;

        self::assertNotSame([], $this->reset->abschliessen($token, 'kurz', 'kurz')->fehler);
        self::assertNotSame([], $this->reset->abschliessen($token, self::NEU, self::NEU . 'x')->fehler);

        self::assertTrue($this->reset->abschliessen($token, self::NEU, self::NEU)->istErfolg());
    }

    public function testTheLinkRunsOutAfterThirtyMinutes(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');
        $token = (string) $this->reset->anfordern('erika@example.org', '198.51.100.7', $jetzt)->token;

        self::assertTrue($this->reset->nutzbar($token, $jetzt->modify('+29 minutes')));
        self::assertFalse($this->reset->nutzbar($token, $jetzt->modify('+30 minutes')));
        self::assertFalse($this->reset->abschliessen($token, self::NEU, self::NEU, $jetzt->modify('+31 minutes'))->istErfolg());
    }

    /** One link per account: a new request replaces the old one. */
    public function testANewRequestReplacesTheOldLink(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $alt = (string) $this->reset->anfordern('erika@example.org', '198.51.100.7')->token;
        $neu = (string) $this->reset->anfordern('erika@example.org', '198.51.100.7')->token;

        self::assertFalse($this->reset->nutzbar($alt));
        self::assertTrue($this->reset->nutzbar($neu));
    }

    /** The database holds the digest, never a usable link. */
    public function testOnlyTheDigestIsStored(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $token = (string) $this->reset->anfordern('erika@example.org', '198.51.100.7')->token;

        $gespeichert = (string) $this->pdo()->query('SELECT token_hash FROM auth_token')->fetchColumn();
        self::assertSame(EinmalLink::hash($token), $gespeichert);
        self::assertStringNotContainsString($token, $gespeichert);
    }

    public function testUnknownLockedAndInvitedAccountsGetNoLink(): void
    {
        Benutzerdaten::anlegen($this->pdo(), 'gesperrt@example.org', status: BenutzerStatus::Gesperrt);
        Benutzerdaten::anlegen($this->pdo(), 'eingeladen@example.org', status: BenutzerStatus::Eingeladen);

        foreach (['niemand@example.org', 'gesperrt@example.org', 'eingeladen@example.org', ''] as $i => $email) {
            $anfrage = $this->reset->anfordern($email, '198.51.100.' . $i);
            self::assertFalse($anfrage->zuVieleVersuche, $email);
            self::assertNull($anfrage->token, $email);
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM auth_token')->fetchColumn());
    }

    /** Locked after the mail went out: the link is worth nothing anymore. */
    public function testALockAfterTheRequestVoidsTheLink(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $token = (string) $this->reset->anfordern('erika@example.org', '198.51.100.7')->token;

        new BenutzerRepository($this->pdo())->updateStatus($id, BenutzerStatus::Gesperrt);

        self::assertFalse($this->reset->nutzbar($token));
        self::assertFalse($this->reset->abschliessen($token, self::NEU, self::NEU)->istErfolg());
    }

    /** Each request may send a mail - three per address and window. */
    public function testRequestsPerAddressAreLimited(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');

        for ($i = 0; $i < PasswortReset::JE_KONTO; $i++) {
            self::assertNotNull($this->reset->anfordern('erika@example.org', '203.0.113.' . $i, $jetzt)->token);
        }

        self::assertTrue($this->reset->anfordern('erika@example.org', '203.0.113.99', $jetzt)->zuVieleVersuche);
    }

    public function testGarbageTokensAreNotEvenLookedUp(): void
    {
        foreach (['', 'abc', str_repeat('g', 64), str_repeat('A', 64), str_repeat('a', 64) . "\n"] as $token) {
            self::assertFalse($this->reset->nutzbar($token));
        }
    }
}
