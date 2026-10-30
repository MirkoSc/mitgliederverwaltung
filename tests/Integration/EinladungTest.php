<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BenutzerStatus;
use App\Domain\Rolle;
use App\Repository\BenutzerRepository;
use App\Service\Account\BenutzerRegelVerstoss;
use App\Service\Account\Einladung;
use App\Service\Account\PasswordPolicy;
use App\Service\Migration\Migrator;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/** Inviting an account (docs/spec/02-sicherheit.md section 5). */
final class EinladungTest extends DatabaseTestCase
{
    private const string PASSWORT = 'meine-eigene-lange-parole';

    private Einladung $einladung;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->einladung = new Einladung($this->pdo(), new PasswordPolicy(dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt'));
    }

    public function testTheInvitedPersonSetsThePasswordAndTheAccountIsActive(): void
    {
        $neu = $this->einladung->einladen(' Volker@Example.org ', 'Volker Muster', Rolle::Vorstand);

        $benutzer = new BenutzerRepository($this->pdo())->findById($neu['userId']);
        self::assertSame('volker@example.org', $benutzer?->email);
        self::assertSame(BenutzerStatus::Eingeladen, $benutzer?->status);
        self::assertSame('', $benutzer?->passwordHash, 'no password before the invitation is answered');
        self::assertFalse($benutzer?->darfAnmelden());

        self::assertTrue($this->einladung->nutzbar($neu['token']));
        self::assertTrue($this->einladung->annehmen($neu['token'], self::PASSWORT, self::PASSWORT)->istErfolg());

        $benutzer = new BenutzerRepository($this->pdo())->findById($neu['userId']);
        self::assertSame(BenutzerStatus::Aktiv, $benutzer?->status);
        self::assertSame(Rolle::Vorstand, $benutzer?->rolle);
        self::assertTrue(password_verify(self::PASSWORT, $benutzer?->passwordHash ?? ''));
    }

    /** Issue #87: the Barrierefrei-Modus can be preset and survives accepting the invitation. */
    public function testTheBarrierefreiModusCanBePresetAndIsOffOtherwise(): void
    {
        $mit = $this->einladung->einladen('lena@example.org', 'Lena Lesend', Rolle::Lesend, barrierefrei: true);
        $ohne = $this->einladung->einladen('volker@example.org', 'Volker Muster', Rolle::Vorstand);
        $repo = new BenutzerRepository($this->pdo());

        self::assertTrue($repo->findById($mit['userId'])?->barrierefrei);
        self::assertSame(Rolle::Lesend, $repo->findById($mit['userId'])?->rolle);
        self::assertFalse($repo->findById($ohne['userId'])?->barrierefrei);

        $this->einladung->annehmen($mit['token'], self::PASSWORT, self::PASSWORT);
        self::assertTrue($repo->findById($mit['userId'])?->barrierefrei, 'still on once the account is active');
        self::assertTrue($repo->findById($mit['userId'])?->konto()->barrierefrei, 'and the login sees it');
    }

    public function testTheLinkWorksOnceAndRunsOutAfterThreeDays(): void
    {
        $jetzt = new \DateTimeImmutable('2026-03-02 10:00:00');
        $neu = $this->einladung->einladen('volker@example.org', 'Volker Muster', Rolle::Admin, $jetzt);

        self::assertTrue($this->einladung->nutzbar($neu['token'], $jetzt->modify('+71 hours')));
        self::assertFalse($this->einladung->nutzbar($neu['token'], $jetzt->modify('+72 hours')));

        self::assertTrue($this->einladung->annehmen($neu['token'], self::PASSWORT, self::PASSWORT, $jetzt->modify('+1 hour'))->istErfolg());
        self::assertFalse($this->einladung->annehmen($neu['token'], self::PASSWORT, self::PASSWORT, $jetzt->modify('+2 hours'))->istErfolg());
    }

    public function testARefusedPasswordKeepsTheInvitation(): void
    {
        $neu = $this->einladung->einladen('volker@example.org', 'Volker Muster', Rolle::Vorstand);

        $ergebnis = $this->einladung->annehmen($neu['token'], 'passwort1234', 'passwort1234');

        self::assertFalse($ergebnis->istErfolg());
        self::assertStringContainsString('zu bekannt', $ergebnis->fehler[0]);
        self::assertTrue($this->einladung->nutzbar($neu['token']));
    }

    public function testSendingAgainReplacesTheLink(): void
    {
        $neu = $this->einladung->einladen('volker@example.org', 'Volker Muster', Rolle::Vorstand);

        $erneut = $this->einladung->erneutSenden($neu['userId']);

        self::assertFalse($this->einladung->nutzbar($neu['token']));
        self::assertTrue($this->einladung->nutzbar($erneut['token']));
        self::assertSame('volker@example.org', $erneut['email']);
        self::assertNotNull($this->einladung->gueltigBis($neu['userId']));
    }

    public function testAnActiveAccountGetsNoNewInvitation(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        $this->expectException(BenutzerRegelVerstoss::class);
        $this->einladung->erneutSenden($id);
    }

    public function testAnAddressInUseIsRefused(): void
    {
        Benutzerdaten::anlegen($this->pdo());

        $this->expectExceptionMessage('Für diese E-Mail-Adresse gibt es bereits einen Benutzer.');
        $this->einladung->einladen('ERIKA@example.org', 'Erika Zwei', Rolle::Vorstand);
    }

    public function testNameAndAddressAreChecked(): void
    {
        foreach ([['keine-adresse', 'Volker'], ['volker@example.org', '   '], ['volker@example.org', str_repeat('x', 101)]] as [$email, $name]) {
            try {
                $this->einladung->einladen($email, $name, Rolle::Vorstand);
                self::fail('accepted: ' . $email . ' / ' . $name);
            } catch (BenutzerRegelVerstoss) {
                self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM `user`')->fetchColumn());
            }
        }
    }

    /** Deleted before answering: the link goes with the account (FK cascade). */
    public function testDeletingTheAccountVoidsTheLink(): void
    {
        $neu = $this->einladung->einladen('volker@example.org', 'Volker Muster', Rolle::Vorstand);

        new BenutzerRepository($this->pdo())->delete($neu['userId']);

        self::assertFalse($this->einladung->nutzbar($neu['token']));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM auth_token')->fetchColumn());
    }
}
