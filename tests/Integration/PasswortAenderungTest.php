<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\BenutzerRepository;
use App\Repository\RateLimitRepository;
use App\Service\Account\PasswordPolicy;
use App\Service\Account\PasswortAenderung;
use App\Service\Account\PasswortReset;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/** The own password change from the user menu (06 section 2, 02 section 3). */
final class PasswortAenderungTest extends DatabaseTestCase
{
    private const string NEU = 'eine-neue-lange-parole';

    private PasswortAenderung $aenderung;

    private RateLimiter $limits;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->limits = new RateLimiter(new RateLimitRepository($this->pdo()));
        $this->aenderung = new PasswortAenderung($this->pdo(), $this->limits, self::policy());
    }

    private static function policy(): PasswordPolicy
    {
        return new PasswordPolicy(dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt');
    }

    public function testTheChangeEndsOtherSessionsAndVoidsAnOpenResetLink(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $reset = new PasswortReset($this->pdo(), $this->limits, self::policy());
        $token = (string) $reset->anfordern('erika@example.org', '198.51.100.7')->token;

        $ergebnis = $this->aenderung->aendern($id, Benutzerdaten::PASSWORT, self::NEU, self::NEU);

        self::assertTrue($ergebnis->istErfolg());
        self::assertSame(1, $ergebnis->sessionEpoch);
        self::assertTrue(password_verify(self::NEU, new BenutzerRepository($this->pdo())->findById($id)?->passwordHash ?? ''));
        self::assertFalse($reset->nutzbar($token), 'a link from before the change must not undo it');
    }

    public function testTheCurrentPasswordIsRequiredAndGuessingIsLimited(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        for ($i = 0; $i < PasswortAenderung::JE_KONTO; $i++) {
            self::assertSame(['Das aktuelle Passwort stimmt nicht.'], $this->aenderung->aendern($id, 'geraten-' . $i, self::NEU, self::NEU)->fehler);
        }

        self::assertStringContainsString('Zu viele Versuche', $this->aenderung->aendern($id, Benutzerdaten::PASSWORT, self::NEU, self::NEU)->fehler[0]);
    }

    public function testTheSamePasswordAgainIsRefused(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        $ergebnis = $this->aenderung->aendern($id, Benutzerdaten::PASSWORT, Benutzerdaten::PASSWORT, Benutzerdaten::PASSWORT);

        self::assertStringContainsString('dasselbe wie das bisherige', $ergebnis->fehler[0]);
    }
}
