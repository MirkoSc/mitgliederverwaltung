<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Anwendung;
use App\Dienste;
use App\Domain\BenutzerStatus;
use App\Domain\MfaMethode;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\BenutzerRepository;
use App\Repository\ZweiterFaktorRepository;
use App\Service\Account\Totp;
use App\Service\Account\ZweiterFaktor;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTransport;
use App\Service\Mail\SmtpSecurity;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\FakeMailTransport;

/**
 * The second factor through the whole application (docs/spec/02-sicherheit.md
 * section 3): the second step of the login, the forced setup, the change
 * only after re-confirmation, remembered devices and the security mails -
 * with the real `user` table behind the login guard.
 */
final class ZweiterFaktorFlowTest extends AppTestCase
{
    private ?\Closure $db = null;

    private FakeMailTransport $transport;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->db ??= $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $d = $this->dienste;
        $this->dienste = new Dienste($d->paths, $d->config, $d->version, $d->view, $d->session, $d->logger, pdoFabrik: $this->pdoFabrik());
        $this->kernel = Anwendung::kernel($this->dienste);
        $this->transport = new FakeMailTransport();
        $this->dienste->dienst(MailTransport::class, fn(): MailTransport => $this->transport);

        MailSettingsRepository::aus($this->dienste)->speichern(
            host: 'smtp.example.org',
            port: 587,
            sicherheit: SmtpSecurity::Starttls,
            benutzer: '',
            neuesPasswort: null,
            absender: 'verein@example.org',
            antwortAn: '',
            vereinsname: 'Sportverein Beispielstadt',
            oeffentlicheUrl: 'https://mitglieder.example.org',
        );
    }

    private function pdo(): \PDO
    {
        return $this->dienste->pdo();
    }

    /** @param array<string, string> $cookies */
    private function passwort(string $email = 'erika@example.org', array $cookies = []): Response
    {
        $session = new Session();
        $session->start();

        return $this->aufrufen(new Request(HttpMethod::Post, '/anmelden', post: [
            'email' => $email,
            'passwort' => Benutzerdaten::PASSWORT,
            '_csrf' => $session->csrfToken(),
        ], ip: '198.51.100.7', cookies: $cookies));
    }

    /** @param array<string, string> $mehr */
    private function code(string $code, array $mehr = []): Response
    {
        $session = new Session();
        $session->start();

        return $this->aufrufen(new Request(HttpMethod::Post, '/anmelden/bestaetigen', post: [
            'code' => $code,
            '_csrf' => $session->csrfToken(),
            ...$mehr,
        ], ip: '198.51.100.7'));
    }

    private function letzteMail(): string
    {
        $mail = end($this->transport->gesendet);
        self::assertNotFalse($mail, 'no mail was sent');

        return $mail->body;
    }

    private function codeAusMail(): string
    {
        self::assertSame(1, preg_match('/^(\d{6})$/m', $this->letzteMail(), $m), $this->letzteMail());

        return $m[1];
    }

    private function eingeloggt(): bool
    {
        return new Session()->userId() !== null;
    }

    /** @return list<string> */
    private static function backupCodesAus(Response $seite): array
    {
        preg_match_all('#<li>([0-9A-Z]{5}-[0-9A-Z]{5})</li>#', $seite->body, $m);

        return $m[1];
    }

    public function testTheMailCodeIsTheSecondStepOfTheLogin(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        self::assertWeiterleitungNach('/anmelden/bestaetigen', $this->passwort());
        self::assertFalse($this->eingeloggt(), 'the password alone opens nothing');
        self::assertSame('erika@example.org', end($this->transport->gesendet)->to);
        $code = $this->codeAusMail();

        $seite = $this->get('/anmelden/bestaetigen');
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('autocomplete="one-time-code"', $seite->body);
        self::assertStringContainsString('Wir haben Ihnen einen Code per E-Mail geschickt.', $seite->body);
        self::assertStringContainsString('Code erneut senden', $seite->body);
        self::assertSame(302, $this->get('/app/mitglieder')->status);

        $falsch = $this->code($code === '000000' ? '111111' : '000000');
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('Der Code ist ungültig oder abgelaufen.', $falsch->body);
        self::assertFalse($this->eingeloggt());

        self::assertWeiterleitungNach('/app/mitglieder', $this->code($code));
        self::assertSame($id, new Session()->userId());
        self::assertSame(200, $this->get('/app/mitglieder')->status);
        self::assertStringContainsString('"zweiter_faktor":"email"', (string) $this->pdo()->query("SELECT details FROM audit_log WHERE action = 'anmeldung.erfolgreich'")->fetchColumn());
    }

    public function testAMailCodeOpensOneLoginOnly(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $this->passwort();
        $code = $this->codeAusMail();
        self::assertWeiterleitungNach('/app/mitglieder', $this->code($code));

        $this->abmelden();
        $this->passwort();
        array_pop($this->transport->gesendet);
        self::assertSame(422, $this->code($code)->status);
    }

    /** 02 section 3: at most 5 tries per mail code. */
    public function testFiveWrongTriesEndTheMailCode(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $this->passwort();
        $code = $this->codeAusMail();
        $falsch = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->code($falsch)->status);
        }
        self::assertSame(422, $this->code($code)->status, 'the right code is dead as well');

        // "Code erneut senden" gives a fresh one.
        $this->post('/anmelden/bestaetigen/code-senden');
        self::assertWeiterleitungNach('/app/mitglieder', $this->code($this->codeAusMail()));
    }

    public function testTheCodeAttemptsAreLimitedPerAccount(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $this->passwort();

        for ($i = 0; $i < 8; $i++) {
            $this->code('ABCDE-FGHJK');
        }
        $antwort = $this->code($this->codeAusMail());
        self::assertSame(429, $antwort->status);
        self::assertStringContainsString('Zu viele Versuche.', $antwort->body);
        self::assertFalse($this->eingeloggt());
    }

    public function testTheAppCodeWorksAndEachTimeStepOnlyOnce(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo(), mfa: null);
        $faktor = ZweiterFaktor::aus($this->dienste);
        $geheimnis = $faktor->neuesAppGeheimnis();
        $vorhin = new \DateTimeImmutable('-2 minutes');
        self::assertNotNull($faktor->richteAppEin($id, $geheimnis, Totp::code($geheimnis, $vorhin), $vorhin));

        $gesendet = count($this->transport->gesendet);
        self::assertWeiterleitungNach('/anmelden/bestaetigen', $this->passwort());
        self::assertCount($gesendet, $this->transport->gesendet, 'an app account gets no mail');
        $seite = $this->get('/anmelden/bestaetigen')->body;
        self::assertStringContainsString('Authenticator-App', $seite);
        self::assertStringNotContainsString('Code erneut senden', $seite);

        $code = Totp::code($geheimnis, new \DateTimeImmutable());
        self::assertWeiterleitungNach('/app/mitglieder', $this->code($code));

        $this->abmelden();
        $this->passwort();
        self::assertSame(422, $this->code($code)->status, 'the same code a second time');
    }

    public function testABackupCodeWorksOnceAndRemembersNoDevice(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $codes = ZweiterFaktor::aus($this->dienste)->neueBackupCodes($id);

        $this->passwort();
        $antwort = $this->code($codes[0], ['geraet_merken' => '1']);
        self::assertWeiterleitungNach('/app/mitglieder', $antwort);
        self::assertArrayNotHasKey('Set-Cookie', $antwort->headers, 'a backup code is no device to trust');
        self::assertStringContainsString('mit einem Backup-Code angemeldet. Es sind noch 9 übrig.', $this->get('/app/mitglieder')->body);

        $this->abmelden();
        $this->passwort();
        self::assertSame(422, $this->code($codes[0])->status);
    }

    /** "Dieses Gerät 30 Tage merken": the next login skips the code, until forgotten. */
    public function testARememberedDeviceSkipsTheCode(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $this->passwort();
        $antwort = $this->code($this->codeAusMail(), ['geraet_merken' => '1']);

        self::assertWeiterleitungNach('/app/mitglieder', $antwort);
        $cookie = $antwort->headers['Set-Cookie'] ?? '';
        self::assertSame(1, preg_match('/^geraet=([0-9a-f]{64}); Path=\/; Max-Age=2592000; /', $cookie, $m), $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('Ein Gerät wurde gemerkt', $this->letzteMail(), 'security notice');

        $this->abmelden();
        $gesendet = count($this->transport->gesendet);
        self::assertWeiterleitungNach('/app/mitglieder', $this->passwort(cookies: ['geraet' => $m[1]]));
        self::assertTrue($this->eingeloggt());
        self::assertCount($gesendet, $this->transport->gesendet, 'no code mailed');

        // Another account cannot use the cookie.
        Benutzerdaten::anlegen($this->pdo(), 'volker@example.org');
        $this->abmelden();
        self::assertWeiterleitungNach('/anmelden/bestaetigen', $this->passwort('volker@example.org', ['geraet' => $m[1]]));

        // Forgotten: the code is asked again.
        $this->abmelden();
        $this->passwort(cookies: ['geraet' => $m[1]]);
        self::assertStringContainsString('Gemerkte Geräte</dt>', $this->get('/app/konto/zweiter-faktor')->body);
        $vergessen = $this->post('/app/konto/zweiter-faktor/geraete-vergessen');
        self::assertStringStartsWith('geraet=; Path=/; Max-Age=0', $vergessen->headers['Set-Cookie'] ?? '');
        $this->abmelden();
        self::assertWeiterleitungNach('/anmelden/bestaetigen', $this->passwort(cookies: ['geraet' => $m[1]]));
    }

    public function testALockWhileTheCodeIsPendingEndsTheLogin(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $this->passwort();
        new BenutzerRepository($this->pdo())->updateStatus($id, BenutzerStatus::Gesperrt);

        self::assertWeiterleitungNach('/anmelden', $this->code($this->codeAusMail()));
        self::assertFalse($this->eingeloggt());
    }

    public function testWithoutAnOpenLoginTheConfirmationLeadsBack(): void
    {
        self::assertWeiterleitungNach('/anmelden', $this->get('/anmelden/bestaetigen'));
        self::assertWeiterleitungNach('/anmelden', $this->code('123456'));
    }

    /** 02 section 3: forced setup at the first login, the mail preselected. */
    public function testTheFirstLoginForcesTheSetupByMail(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo(), mfa: null);

        self::assertWeiterleitungNach('/app/mitglieder', $this->passwort());
        self::assertWeiterleitungNach('/app/konto/zweiter-faktor', $this->get('/app/mitglieder'));
        $seite = $this->get('/app/konto/zweiter-faktor')->body;
        self::assertStringContainsString('Zweiten Faktor einrichten', $seite);
        self::assertMatchesRegularExpression('/value="email" checked/', $seite);
        self::assertStringContainsString('value="totp"', $seite);

        self::assertWeiterleitungNach('/app/konto/zweiter-faktor', $this->post('/app/konto/zweiter-faktor/einrichten', ['methode' => 'email']));
        $code = $this->codeAusMail();
        self::assertStringContainsString('Wir haben einen 6-stelligen Code an erika@example.org geschickt.', $this->get('/app/konto/zweiter-faktor')->body);

        self::assertSame(422, $this->post('/app/konto/zweiter-faktor/abschliessen', ['code' => '99999'])->status);
        $fertig = $this->post('/app/konto/zweiter-faktor/abschliessen', ['code' => $code]);
        self::assertSame(200, $fertig->status);
        self::assertStringContainsString('Ihr zweiter Faktor ist jetzt „Code per E-Mail“', $fertig->body);
        self::assertCount(10, self::backupCodesAus($fertig));
        self::assertStringContainsString('data-drucken', $fertig->body);
        self::assertStringContainsString('zweite Faktor eingerichtet oder geändert', $this->letzteMail(), 'security notice');

        self::assertSame(MfaMethode::Email, new BenutzerRepository($this->pdo())->findById($id)?->mfaMethod);
        self::assertSame(200, $this->get('/app/mitglieder')->status);
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'zweiter_faktor.geaendert'")->fetchColumn());
    }

    /** "Ist die Mail noch nicht eingerichtet, bietet die Einrichtung nur die App an und sagt warum." */
    public function testWithoutMailSettingsOnlyTheAppIsOffered(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo(), mfa: null);
        $this->passwort();
        $this->dienste->einstellungen()->delete('mail_smtp_host');

        $seite = $this->get('/app/konto/zweiter-faktor')->body;
        self::assertStringNotContainsString('value="email"', $seite);
        self::assertMatchesRegularExpression('/value="totp" checked/', $seite);
        self::assertStringContainsString('Mailversand der Mitgliederverwaltung ist noch nicht eingerichtet', $seite);

        $this->post('/app/konto/zweiter-faktor/einrichten', ['methode' => 'email']);
        self::assertNull(new BenutzerRepository($this->pdo())->findById($id)?->mfaMethod);
        self::assertStringContainsString('Bitte wählen Sie', $this->get('/app/konto/zweiter-faktor')->body);

        // The app: QR code plus the key as text.
        $this->post('/app/konto/zweiter-faktor/einrichten', ['methode' => 'totp']);
        $einrichtung = $this->get('/app/konto/zweiter-faktor')->body;
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $einrichtung);
        self::assertSame(1, preg_match('#<span class="schluessel">([A-Z2-7 ]+)</span>#', $einrichtung, $m));
        $geheimnis = Totp::base32Decode($m[1]);
        self::assertSame(20, strlen($geheimnis));

        $fertig = $this->post('/app/konto/zweiter-faktor/abschliessen', ['code' => Totp::code($geheimnis, new \DateTimeImmutable())]);
        self::assertSame(200, $fertig->status);
        self::assertCount(10, self::backupCodesAus($fertig));
        self::assertSame(MfaMethode::App, new BenutzerRepository($this->pdo())->findById($id)?->mfaMethod);
        self::assertNotNull(new ZweiterFaktorRepository($this->pdo())->appGeheimnis($id));
    }

    /** "Ändern nur nach erneuter Bestätigung": password and current code first. */
    public function testAChangeNeedsPasswordAndCodeAgain(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $codes = ZweiterFaktor::aus($this->dienste)->neueBackupCodes($id);
        $this->passwort();
        $this->code($this->codeAusMail());

        $seite = $this->get('/app/konto/zweiter-faktor')->body;
        self::assertStringContainsString('Code per E-Mail', $seite);
        self::assertStringContainsString('10 von 10 übrig', $seite);
        self::assertStringContainsString('action="/app/konto/zweiter-faktor/bestaetigen"', $seite);
        self::assertStringNotContainsString('name="methode"', $seite);

        // Not without the confirmation.
        $this->post('/app/konto/zweiter-faktor/einrichten', ['methode' => 'totp']);
        self::assertStringNotContainsString('<svg', $this->get('/app/konto/zweiter-faktor')->body);
        self::assertSame([], self::backupCodesAus($this->post('/app/konto/zweiter-faktor/backup-codes')));

        $falsch = $this->post('/app/konto/zweiter-faktor/bestaetigen', ['passwort' => 'nicht-das-passwort', 'code' => $codes[1]]);
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('Das Passwort stimmt nicht.', $falsch->body);

        // A mail code for the confirmation.
        $this->post('/app/konto/zweiter-faktor/code-senden');
        self::assertWeiterleitungNach('/app/konto/zweiter-faktor', $this->post('/app/konto/zweiter-faktor/bestaetigen', [
            'passwort' => Benutzerdaten::PASSWORT,
            'code' => $this->codeAusMail(),
        ]));
        self::assertStringContainsString('name="methode"', $this->get('/app/konto/zweiter-faktor')->body);

        $neu = $this->post('/app/konto/zweiter-faktor/backup-codes');
        self::assertCount(10, self::backupCodesAus($neu));
        self::assertStringContainsString('neue Backup-Codes erzeugt', $this->letzteMail());
        self::assertStringNotContainsString('name="methode"', $this->get('/app/konto/zweiter-faktor')->body, 'the confirmation is spent');
        self::assertNull(ZweiterFaktor::aus($this->dienste)->pruefe($id, $codes[2]), 'the old codes are gone');
    }

    public function testAnAppAccountGetsNoMailCodeForTheConfirmation(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo(), mfa: null);
        $faktor = ZweiterFaktor::aus($this->dienste);
        $geheimnis = $faktor->neuesAppGeheimnis();
        $vorhin = new \DateTimeImmutable('-2 minutes');
        $faktor->richteAppEin($id, $geheimnis, Totp::code($geheimnis, $vorhin), $vorhin);
        $this->passwort();
        $this->code(Totp::code($geheimnis, new \DateTimeImmutable()));
        $gesendet = count($this->transport->gesendet);

        self::assertStringNotContainsString('Code per E-Mail senden', $this->get('/app/konto/zweiter-faktor')->body);
        $this->post('/app/konto/zweiter-faktor/code-senden');
        self::assertCount($gesendet, $this->transport->gesendet);
    }
}
