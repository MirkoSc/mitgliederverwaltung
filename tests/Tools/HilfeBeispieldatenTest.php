<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Config\Config;
use App\Domain\MfaMethode;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\BenutzerRepository;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;
use App\Repository\RateLimitRepository;
use App\Repository\SettingRepository;
use App\Repository\ZweiterFaktorRepository;
use App\Service\Account\Totp;
use App\Service\Account\ZweiterFaktor;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\RateLimiter;
use App\Service\Verein\VereinRepository;
use App\Support\Alter;
use App\Support\Iban;
use App\Tests\Support\DatabaseTestCase;
use App\Tools\HilfeBeispieldaten;

require_once dirname(__DIR__, 2) . '/tools/hilfe-beispieldaten.php';

/**
 * tools/hilfe-beispieldaten.php (docs/spec/12-hilfe.md sections 5 and 7):
 * refuses without `debug`, creates invented members and the two demo
 * accounts, and gives the same result when run again.
 */
final class HilfeBeispieldatenTest extends DatabaseTestCase
{
    private const string KONTEN = __DIR__ . '/../../tools/hilfe-screenshots/konten.json';

    private \DateTimeImmutable $heute;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->heute = new \DateTimeImmutable('2026-11-05 00:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    public function testRefusesWithoutDebugAndTouchesNothing(): void
    {
        $mitglieder = new MitgliedRepository($this->pdo());
        $mitglieder->speichern(new Mitglied(mitgliedsnummer: 7, nachname: 'Echt'), VersionsAnlass::angelegt(), null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/debug => true/');
        try {
            new HilfeBeispieldaten($this->pdo(), $this->config(false), self::KONTEN);
        } finally {
            self::assertSame(1, $mitglieder->anzahl(), 'nothing was deleted');
        }
    }

    public function testCreatesInventedMembersFamiliesAndClubData(): void
    {
        $ergebnis = $this->helfer()->anlegen($this->heute);

        $mitglieder = new MitgliedRepository($this->pdo());
        self::assertSame(['mitglieder' => 31, 'familien' => 5, 'konten' => 2], $ergebnis);
        self::assertSame(31, $mitglieder->anzahl());
        self::assertCount(30, $mitglieder->alle(false, $this->heute), 'one former member besides the 30');
        self::assertNotNull($mitglieder->findeNachNummer(HilfeBeispieldaten::ERSTE_NUMMER));
        self::assertNotNull($mitglieder->findeNachNummer(HilfeBeispieldaten::ERSTE_NUMMER + 30));
        self::assertNull($mitglieder->findeNachNummer(HilfeBeispieldaten::ERSTE_NUMMER - 1));
        self::assertSame(5, (int) $this->pdo()->query('SELECT COUNT(*) FROM familie')->fetchColumn());

        foreach ($mitglieder->alle(true) as $mitglied) {
            self::assertGreaterThanOrEqual(HilfeBeispieldaten::ERSTE_NUMMER, $mitglied->mitgliedsnummer);
            self::assertTrue($mitglied->email === '' || str_ends_with($mitglied->email, '@example.org'), 'only example addresses');
            if ($mitglied->iban !== '') {
                self::assertTrue(Iban::istGueltig($mitglied->iban), $mitglied->iban);
            }
        }

        $verein = new VereinRepository($this->pdo(), new SettingRepository($this->pdo()));
        self::assertSame('SV Musterstadt e. V.', $verein->get()->wert('vereinsname'));
        self::assertTrue($verein->get()->lastschriftMoeglich());
        self::assertTrue(Iban::istGueltig($verein->get()->wert('iban')));
    }

    public function testFamiliesHaveOnePayerEach(): void
    {
        $this->helfer()->anlegen($this->heute);

        $mitglieder = new MitgliedRepository($this->pdo());
        $familien = new FamilieRepository($this->pdo());
        $gefunden = 0;
        foreach ($familien->suche('Familie') as $familie) {
            $gefunden++;
            $rollen = array_map(
                static fn(Mitglied $m): string => $m->familieRolle?->value ?? '',
                $mitglieder->derFamilie($familie->id),
            );
            self::assertSame(1, count(array_filter($rollen, static fn(string $r): bool => $r === 'zahler')), $familie->name);
        }
        self::assertSame(5, $gefunden);
    }

    public function testBirthdaysAreRelativeToTodayAndOneIsRound(): void
    {
        $this->helfer()->anlegen($this->heute);

        $bald = 0;
        $rund = [];
        foreach (new MitgliedRepository($this->pdo())->alle(false, $this->heute) as $mitglied) {
            $naechster = $mitglied->geburtsdatum;
            self::assertNotNull($naechster);
            for ($tage = 0; $tage <= 30; $tage++) {
                $tag = $this->heute->modify("+$tage days");
                if ($naechster->format('m-d') === $tag->format('m-d')) {
                    $bald++;
                    $alter = Alter::am($naechster, $tag);
                    if (in_array($alter, [18, 30, 40, 50, 60, 65, 70, 75, 80, 85, 90], true)) {
                        $rund[] = $alter;
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(8, $bald, 'the birthday list has entries in the month');
        self::assertContains(60, $rund, 'a round birthday is among them');
    }

    public function testBirthDateFitsTheNextBirthday(): void
    {
        $geburt = HilfeBeispieldaten::geburtsdatum($this->heute, 60, 4);

        self::assertSame('1966-11-09', $geburt->format('Y-m-d'));
        self::assertSame(60, Alter::am($geburt, $this->heute->modify('+4 days')));
        self::assertSame(59, Alter::am($geburt, $this->heute->modify('+3 days')));
    }

    public function testBirthDateAvoidsTheLeapDay(): void
    {
        $heute = new \DateTimeImmutable('2028-02-26');

        self::assertSame('1988-03-01', HilfeBeispieldaten::geburtsdatum($heute, 40, 3)->format('Y-m-d'));
        self::assertSame('1988-03-01', HilfeBeispieldaten::geburtsdatum($heute, 40, 4)->format('Y-m-d'));
    }

    public function testIbanHasValidCheckDigits(): void
    {
        foreach (['123456780000099999', '123456781001001000', '000000000000000000'] as $bban) {
            self::assertTrue(Iban::istGueltig(HilfeBeispieldaten::iban($bban)), $bban);
        }
        self::assertSame('DE', substr(HilfeBeispieldaten::iban('123456780000099999'), 0, 2));
    }

    public function testDemoAccountsHaveTheRoleModeAndAnAuthenticatorApp(): void
    {
        $this->helfer()->anlegen($this->heute);

        $konten = json_decode((string) file_get_contents(self::KONTEN), true, 512, JSON_THROW_ON_ERROR);
        $benutzer = new BenutzerRepository($this->pdo());
        $faktor = $this->zweiterFaktor();

        foreach (['normal' => false, 'barrierefrei' => true] as $schluessel => $barrierefrei) {
            $konto = $konten['konten'][$schluessel];
            $nutzer = $benutzer->findByExactEmail($konto['email']);
            self::assertNotNull($nutzer, $schluessel);
            self::assertSame(Rolle::Vorstand, $nutzer->rolle);
            self::assertSame($barrierefrei, $nutzer->barrierefrei);
            self::assertSame(MfaMethode::App, $nutzer->mfaMethod);
            self::assertTrue($nutzer->darfAnmelden());

            // The screenshot script logs in with the current step right after the setup.
            $geheimnis = Totp::base32Decode($konto['totp_geheimnis']);
            self::assertTrue($faktor->pruefeApp($nutzer->id, Totp::code($geheimnis, new \DateTimeImmutable())), $schluessel);
        }
    }

    public function testRunningAgainGivesTheSameResultAndRenewsTheAccounts(): void
    {
        $helfer = $this->helfer();
        $erstes = $helfer->anlegen($this->heute);
        $benutzer = new BenutzerRepository($this->pdo());
        $konto = json_decode((string) file_get_contents(self::KONTEN), true, 512, JSON_THROW_ON_ERROR)['konten']['normal'];
        $alteId = $benutzer->findByExactEmail($konto['email'])?->id;
        $nummern = $this->nummern();

        // A change a recording made: the next run starts from scratch.
        $benutzer->updateBarrierefrei((int) $alteId, true);
        new MitgliedRepository($this->pdo())->speichern(new Mitglied(mitgliedsnummer: 5000, nachname: 'Zusatz'), VersionsAnlass::angelegt(), null);

        self::assertSame($erstes, $helfer->anlegen($this->heute));
        self::assertSame($nummern, $this->nummern());
        self::assertSame(5, (int) $this->pdo()->query('SELECT COUNT(*) FROM familie')->fetchColumn());
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM `user`')->fetchColumn(), 'accounts are not duplicated');
        $neu = $benutzer->findByExactEmail($konto['email']);
        self::assertNotNull($neu);
        self::assertNotSame($alteId, $neu->id);
        self::assertFalse($neu->barrierefrei, 'the mode is back as defined in konten.json');
        self::assertTrue($this->zweiterFaktor()->pruefeApp($neu->id, Totp::code(Totp::base32Decode($konto['totp_geheimnis']), new \DateTimeImmutable())));
    }

    public function testOtherAccountsStayUntouched(): void
    {
        $benutzer = new BenutzerRepository($this->pdo());
        $fremd = $benutzer->insert('admin@example.org', 'Erika', 'x', \App\Domain\BenutzerStatus::Aktiv, Rolle::Admin, new \DateTimeImmutable());

        $this->helfer()->anlegen($this->heute);

        self::assertNotNull($benutzer->findById($fremd));
    }

    /** @return list<int> */
    private function nummern(): array
    {
        return array_map(
            static fn(Mitglied $m): int => $m->mitgliedsnummer,
            new MitgliedRepository($this->pdo())->alle(true),
        );
    }

    private function helfer(): HilfeBeispieldaten
    {
        return new HilfeBeispieldaten($this->pdo(), $this->config(true), self::KONTEN);
    }

    private function config(bool $debug): Config
    {
        return Config::fromArray(['debug' => $debug] + self::configData());
    }

    private function zweiterFaktor(): ZweiterFaktor
    {
        $config = $this->config(true);

        return new ZweiterFaktor(
            new ZweiterFaktorRepository($this->pdo()),
            new BenutzerRepository($this->pdo()),
            new ServerCrypto($config->serverKey, $config->serverKeyAlt),
            new RateLimiter(new RateLimitRepository($this->pdo())),
        );
    }
}
