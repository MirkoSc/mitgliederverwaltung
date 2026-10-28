<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BenutzerStatus;
use App\Domain\Rolle;
use App\Repository\AuthTokenRepository;
use App\Repository\BenutzerRepository;
use App\Service\Account\Benutzerkonten;
use App\Service\Account\BenutzerRegelVerstoss;
use App\Service\Account\BenutzerVerwaltung;
use App\Service\Account\EinmalLink;
use App\Service\Account\PasswordPolicy;
use App\Service\Migration\Migrator;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\DatabaseTestCase;

/**
 * The user administration (docs/spec/02-sicherheit.md section 5) and the
 * account source of the login guard (section 4: a lock takes effect at
 * once).
 */
final class BenutzerVerwaltungTest extends DatabaseTestCase
{
    private BenutzerVerwaltung $verwaltung;

    private BenutzerRepository $repo;

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->verwaltung = new BenutzerVerwaltung($this->pdo(), new PasswordPolicy(dirname(__DIR__, 2) . '/app/data/haeufige-passwoerter.txt'));
        $this->repo = new BenutzerRepository($this->pdo());
        $this->admin = Benutzerdaten::anlegen($this->pdo(), 'admin@example.org', Rolle::Admin, name: 'Anna Admin');
    }

    public function testTheRoleChanges(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        $this->verwaltung->rolleAendern($this->admin, $id, Rolle::Admin);

        self::assertSame(Rolle::Admin, $this->repo->findById($id)?->rolle);
    }

    public function testLockingEndsTheSessionsAndDropsOpenLinks(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        new AuthTokenRepository($this->pdo())->insert($id, 'reset', EinmalLink::hash(EinmalLink::neu()), new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable());
        $konten = new Benutzerkonten(fn(): \PDO => $this->pdo());
        self::assertNotNull($konten->konto($id));

        $this->verwaltung->sperren($this->admin, $id);

        $benutzer = $this->repo->findById($id);
        self::assertSame(BenutzerStatus::Gesperrt, $benutzer?->status);
        self::assertSame(1, $benutzer?->sessionEpoch);
        self::assertNull($konten->konto($id), 'the login guard no longer finds the account');
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM auth_token')->fetchColumn());

        $this->verwaltung->entsperren($id);
        self::assertNotNull($konten->konto($id));
    }

    public function testAnInvitedOrDeletedAccountHasNoSession(): void
    {
        $eingeladen = Benutzerdaten::anlegen($this->pdo(), 'neu@example.org', status: BenutzerStatus::Eingeladen);
        $konten = new Benutzerkonten(fn(): \PDO => $this->pdo());

        self::assertNull($konten->konto($eingeladen));
        self::assertNull($konten->konto(999999));

        $konto = $konten->konto($this->admin);
        self::assertSame('Anna Admin', $konto?->name);
        self::assertSame(Rolle::Admin, $konto?->rolle);
    }

    public function testDeletingRemovesTheAccount(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        $this->verwaltung->loeschen($this->admin, $id);

        self::assertNull($this->repo->findById($id));
    }

    /** The click that would lock out the person who is clicking. */
    public function testTheOwnAccountIsNeverLockedDeletedOrDemoted(): void
    {
        Benutzerdaten::anlegen($this->pdo(), 'zweiter@example.org', Rolle::Admin);

        foreach ([
            fn() => $this->verwaltung->sperren($this->admin, $this->admin),
            fn() => $this->verwaltung->loeschen($this->admin, $this->admin),
            fn() => $this->verwaltung->rolleAendern($this->admin, $this->admin, Rolle::Vorstand),
        ] as $schritt) {
            try {
                $schritt();
                self::fail('the own account was changed');
            } catch (BenutzerRegelVerstoss) {
            }
        }

        $selbst = $this->repo->findById($this->admin);
        self::assertSame(BenutzerStatus::Aktiv, $selbst?->status);
        self::assertSame(Rolle::Admin, $selbst?->rolle);
    }

    /** Another admin cannot take the last active admin away either. */
    public function testTheLastActiveAdminStays(): void
    {
        // A locked second admin does not count.
        $zweiter = Benutzerdaten::anlegen($this->pdo(), 'zweiter@example.org', Rolle::Admin, BenutzerStatus::Gesperrt);
        $this->repo->updateStatus($zweiter, BenutzerStatus::Aktiv);
        $this->verwaltung->sperren($this->admin, $zweiter);

        foreach ([
            fn() => $this->verwaltung->sperren($zweiter, $this->admin),
            fn() => $this->verwaltung->loeschen($zweiter, $this->admin),
            fn() => $this->verwaltung->rolleAendern($zweiter, $this->admin, Rolle::Vorstand),
        ] as $schritt) {
            try {
                $schritt();
                self::fail('the last active admin was taken away');
            } catch (BenutzerRegelVerstoss $e) {
                self::assertStringContainsString('letzte aktive Admin', $e->getMessage());
            }
        }
    }

    public function testWithASecondActiveAdminTheFirstMayGo(): void
    {
        $zweiter = Benutzerdaten::anlegen($this->pdo(), 'zweiter@example.org', Rolle::Admin);

        $this->verwaltung->rolleAendern($zweiter, $this->admin, Rolle::Vorstand);

        self::assertSame(Rolle::Vorstand, $this->repo->findById($this->admin)?->rolle);
    }

    public function testOnlyAnActiveAccountIsLockedAndOnlyALockedOneUnlocked(): void
    {
        $eingeladen = Benutzerdaten::anlegen($this->pdo(), 'neu@example.org', status: BenutzerStatus::Eingeladen);

        try {
            $this->verwaltung->sperren($this->admin, $eingeladen);
            self::fail('an invitation was locked');
        } catch (BenutzerRegelVerstoss) {
        }

        $this->expectException(BenutzerRegelVerstoss::class);
        $this->verwaltung->entsperren($this->admin);
    }

    public function testTheFirstAdminIsActiveAndFollowsThePasswordRule(): void
    {
        $id = $this->verwaltung->ersterAdmin('Neu@Example.org', 'Neue Admin', 'lange-sichere-parole-eins', 'lange-sichere-parole-eins');

        $benutzer = $this->repo->findById($id);
        self::assertSame('neu@example.org', $benutzer?->email);
        self::assertSame(Rolle::Admin, $benutzer?->rolle);
        self::assertTrue($benutzer?->darfAnmelden());

        $this->expectExceptionMessage('mindestens 12 Zeichen');
        $this->verwaltung->ersterAdmin('kurz@example.org', 'Kurz', 'kurz', 'kurz');
    }

    public function testAnUnknownAccountIsReportedNotIgnored(): void
    {
        $this->expectExceptionMessage('Diesen Benutzer gibt es nicht mehr.');
        $this->verwaltung->loeschen($this->admin, 999999);
    }

    public function testTheListIsSortedByName(): void
    {
        Benutzerdaten::anlegen($this->pdo(), 'z@example.org', name: 'Zora Zett');
        Benutzerdaten::anlegen($this->pdo(), 'b@example.org', name: 'Bernd Beispiel');

        self::assertSame(['Anna Admin', 'Bernd Beispiel', 'Zora Zett'], array_map(static fn($b) => $b->name, $this->repo->alle()));
        self::assertTrue($this->repo->gibtEs());
    }
}
