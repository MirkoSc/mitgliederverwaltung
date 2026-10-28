<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Anwendung;
use App\Dienste;
use App\Domain\BenutzerStatus;
use App\Domain\Rolle;
use App\Repository\BenutzerRepository;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTransport;
use App\Service\Mail\SmtpSecurity;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\Benutzerdaten;
use App\Tests\Support\FakeMailTransport;
use App\Http\Session;

/**
 * Login, logout, reset, invitation, own password and the settings section
 * Benutzer through the whole application - with the real `user` table
 * behind the login guard (App\Service\Account\Benutzerkonten), not the
 * test accounts of AppTestCase::als().
 */
final class AnmeldungFlowTest extends AppTestCase
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
        // The application's own account source instead of TestKonten.
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

    private function anmelden(string $email = 'erika@example.org', string $passwort = Benutzerdaten::PASSWORT, array $mehr = []): \App\Http\Response
    {
        return $this->post('/anmelden', ['email' => $email, 'passwort' => $passwort, ...$mehr]);
    }

    /** The link of the last mail (a reset or an invitation). */
    private function linkAusLetzterMail(): string
    {
        $mail = end($this->transport->gesendet);
        self::assertNotFalse($mail, 'no mail was sent');
        self::assertSame(1, preg_match('#https://mitglieder\.example\.org(/anmelden/[a-z-]+)\?token=([0-9a-f]{64})#', $mail->body, $m), $mail->body);

        return $m[1] . '?token=' . $m[2];
    }

    private function holen(string $link): \App\Http\Response
    {
        [$pfad, $query] = explode('?', $link, 2);
        parse_str($query, $parameter);

        return $this->get($pfad, array_map('strval', $parameter));
    }

    public function testTheLoginPageHasTheFormAndNoDevelopmentAccounts(): void
    {
        $seite = $this->get('/anmelden');

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('<form method="post" action="/anmelden"', $seite->body);
        self::assertStringContainsString('autocomplete="current-password"', $seite->body);
        self::assertStringContainsString('href="/anmelden/passwort-vergessen"', $seite->body);
        self::assertStringNotContainsString('ansehen</button>', $seite->body);
    }

    public function testTheRightPasswordOpensTheFirstPageOfTheRole(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());

        self::assertWeiterleitungNach('/app/mitglieder', $this->anmelden());
        self::assertSame($id, new Session()->userId());
        self::assertSame(200, $this->get('/app/mitglieder')->status);
        self::assertSame(403, $this->get('/admin')->status, 'Vorstand');
        self::assertNotNull(new BenutzerRepository($this->pdo())->findById($id)?->lastLoginAt);
    }

    public function testAfterTheLoginTheRequestedPageOpensButNoForeignHost(): void
    {
        Benutzerdaten::anlegen($this->pdo());

        self::assertWeiterleitungNach('/app/listen', $this->anmelden(mehr: ['weiter' => '/app/listen']));
        $this->abmelden();
        self::assertWeiterleitungNach('/app/mitglieder', $this->anmelden(mehr: ['weiter' => '//evil.example/app']));
        $this->abmelden();
        self::assertWeiterleitungNach('/app/mitglieder', $this->anmelden(mehr: ['weiter' => 'https://evil.example']));
    }

    /** The guard's ?weiter= arrives in the form. */
    public function testTheLoginFormKeepsWhereTheVisitorWanted(): void
    {
        $umleitung = $this->get('/app/listen');
        self::assertWeiterleitungNach('/anmelden?weiter=%2Fapp%2Flisten', $umleitung);

        self::assertStringContainsString('name="weiter" value="/app/listen"', $this->get('/anmelden', ['weiter' => '/app/listen'])->body);
    }

    /** Every wrong combination gives the very same page (no enumeration). */
    public function testEveryFailureLooksTheSame(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        Benutzerdaten::anlegen($this->pdo(), 'gesperrt@example.org', status: BenutzerStatus::Gesperrt);
        Benutzerdaten::anlegen($this->pdo(), 'eingeladen@example.org', status: BenutzerStatus::Eingeladen);

        $seiten = [];
        foreach ([
            ['niemand@example.org', Benutzerdaten::PASSWORT],
            ['erika@example.org', 'ganz-falsches-passwort'],
            ['gesperrt@example.org', Benutzerdaten::PASSWORT],
            ['eingeladen@example.org', Benutzerdaten::PASSWORT],
        ] as [$email, $passwort]) {
            $antwort = $this->anmelden($email, $passwort);
            self::assertSame(422, $antwort->status);
            self::assertStringContainsString('E-Mail-Adresse oder Passwort ist falsch.', $antwort->body);
            self::assertNull(new Session()->userId());
            // Only the typed address and the token differ.
            $seiten[] = preg_replace(['/value="[^"]*@example\.org"/', '/value="[0-9a-f]{64}"/'], '', $antwort->body);
        }

        self::assertCount(1, array_unique($seiten));
    }

    public function testLogoutEndsTheSession(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $this->anmelden();

        self::assertWeiterleitungNach('/anmelden', $this->post('/abmelden'));
        self::assertNull(new Session()->userId());
        self::assertSame(302, $this->get('/app/mitglieder')->status);
    }

    /** 02 section 4: a lock takes effect on the next click. */
    public function testALockEndsALiveSessionAtOnce(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $this->anmelden();

        new BenutzerRepository($this->pdo())->updateStatus($id, BenutzerStatus::Gesperrt);

        $antwort = $this->get('/app/mitglieder');
        self::assertSame(302, $antwort->status);
        self::assertStringContainsString('abgelaufen=1', $antwort->headers['Location']);
    }

    /** A new role is in force on the next click - no new login needed. */
    public function testARoleChangeTakesEffectAtOnce(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $this->anmelden();
        self::assertSame(403, $this->get('/app/beitraege')->status);

        new BenutzerRepository($this->pdo())->updateRolle($id, Rolle::Admin);

        self::assertSame(200, $this->get('/app/beitraege')->status);
        self::assertSame('admin', $_SESSION['rolle'] ?? null);
    }

    public function testTheResetMailLeadsToANewPasswordAndEndsOldSessions(): void
    {
        Benutzerdaten::anlegen($this->pdo());
        $this->anmelden();
        $alteSitzung = $_SESSION;
        $this->abmelden();

        $antwort = $this->post('/anmelden/passwort-vergessen', ['email' => 'erika@example.org']);
        self::assertStringContainsString('Gehört die Adresse zu einem Konto', $antwort->body);
        self::assertCount(1, $this->transport->gesendet);
        self::assertSame('erika@example.org', $this->transport->gesendet[0]->to);
        $link = $this->linkAusLetzterMail();
        self::assertStringStartsWith('/anmelden/passwort-neu?token=', $link);

        $formular = $this->holen($link);
        self::assertSame(200, $formular->status);
        self::assertSame('no-referrer', $formular->headers['Referrer-Policy'] ?? null);

        parse_str(explode('?', $link, 2)[1], $q);
        $neu = 'ein-ganz-neues-langes-passwort';
        self::assertWeiterleitungNach('/anmelden', $this->post('/anmelden/passwort-neu', ['token' => (string) $q['token'], 'passwort' => $neu, 'passwort_wiederholung' => $neu]));
        self::assertStringContainsString('Ihr neues Passwort ist gespeichert.', $this->get('/anmelden')->body);
        self::assertStringContainsString('über „Passwort vergessen“ neu festgelegt', end($this->transport->gesendet)->body, 'security notice');

        // The session from before the reset is over.
        $_SESSION = $alteSitzung;
        self::assertSame(302, $this->get('/app/mitglieder')->status);

        // The link is spent; the new password works, the old one not.
        self::assertSame(410, $this->holen($link)->status);
        $this->abmelden();
        self::assertSame(422, $this->anmelden()->status);
        self::assertWeiterleitungNach('/app/mitglieder', $this->anmelden(passwort: $neu));
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoMail(): void
    {
        $antwort = $this->post('/anmelden/passwort-vergessen', ['email' => 'niemand@example.org']);

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Gehört die Adresse zu einem Konto', $antwort->body);
        self::assertSame([], $this->transport->gesendet);
    }

    public function testAnInvalidLinkSaysSoAndOffersANewOne(): void
    {
        $antwort = $this->get('/anmelden/passwort-neu', ['token' => str_repeat('a', 64)]);

        self::assertSame(410, $antwort->status);
        self::assertStringContainsString('ungültig oder abgelaufen', $antwort->body);
        self::assertStringContainsString('href="/anmelden/passwort-vergessen"', $antwort->body);
    }

    public function testTheAdminInvitesAndTheInvitedPersonLogsIn(): void
    {
        Benutzerdaten::anlegen($this->pdo(), 'admin@example.org', Rolle::Admin, name: 'Anna Admin');
        $this->anmelden('admin@example.org');

        $einstellungen = $this->get('/admin');
        self::assertStringContainsString('id="benutzer"', $einstellungen->body);
        self::assertStringContainsString('Anna Admin', $einstellungen->body);

        self::assertWeiterleitungNach('/admin#benutzer', $this->post('/admin/benutzer', ['name' => 'Volker Muster', 'email' => 'volker@example.org', 'rolle' => 'vorstand']));
        self::assertStringContainsString('Die Einladung ist versendet.', $this->get('/admin')->body);
        self::assertSame('volker@example.org', end($this->transport->gesendet)->to);
        $link = $this->linkAusLetzterMail();
        self::assertStringStartsWith('/anmelden/einladung?token=', $link);
        $this->post('/abmelden');

        self::assertStringContainsString('Einladung annehmen', $this->holen($link)->body);
        parse_str(explode('?', $link, 2)[1], $q);
        $passwort = 'volkers-eigene-lange-parole';
        self::assertWeiterleitungNach('/anmelden', $this->post('/anmelden/einladung', ['token' => (string) $q['token'], 'passwort' => $passwort, 'passwort_wiederholung' => $passwort]));

        self::assertWeiterleitungNach('/app/mitglieder', $this->anmelden('volker@example.org', $passwort));
    }

    public function testTheAdminLocksChangesTheRoleAndDeletes(): void
    {
        Benutzerdaten::anlegen($this->pdo(), 'admin@example.org', Rolle::Admin, name: 'Anna Admin');
        $id = Benutzerdaten::anlegen($this->pdo(), name: 'Erika Beispiel');
        $this->anmelden('admin@example.org');
        $repo = new BenutzerRepository($this->pdo());

        $this->post('/admin/benutzer/' . $id . '/rolle', ['rolle' => 'admin']);
        self::assertSame(Rolle::Admin, $repo->findById($id)?->rolle);

        $this->post('/admin/benutzer/' . $id . '/sperren');
        self::assertSame(BenutzerStatus::Gesperrt, $repo->findById($id)?->status);
        self::assertStringContainsString('Erika Beispiel ist gesperrt', $this->get('/admin')->body);

        $this->post('/admin/benutzer/' . $id . '/entsperren');
        self::assertSame(BenutzerStatus::Aktiv, $repo->findById($id)?->status);

        $this->post('/admin/benutzer/' . $id . '/loeschen');
        self::assertNotNull($repo->findById($id), 'not without the tick');
        self::assertStringContainsString('Bitte bestätigen Sie das Löschen', $this->get('/admin')->body);

        $this->post('/admin/benutzer/' . $id . '/loeschen', ['bestaetigt' => '1']);
        self::assertNull($repo->findById($id));
    }

    public function testTheAdminCannotLockThemselves(): void
    {
        $selbst = Benutzerdaten::anlegen($this->pdo(), 'admin@example.org', Rolle::Admin);
        $this->anmelden('admin@example.org');

        $this->post('/admin/benutzer/' . $selbst . '/sperren');

        self::assertStringContainsString('Das eigene Konto können Sie nicht sperren.', $this->get('/admin')->body);
        self::assertSame(BenutzerStatus::Aktiv, new BenutzerRepository($this->pdo())->findById($selbst)?->status);
    }

    public function testTheOwnPasswordChangesAndOtherSessionsEnd(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $this->anmelden();
        $andereSitzung = $_SESSION;
        $this->anmelden();

        self::assertStringContainsString('Aktuelles Passwort', $this->get('/app/konto/passwort')->body);

        $falsch = $this->post('/app/konto/passwort', ['aktuell' => 'nicht-das-passwort', 'passwort' => 'neues-langes-passwort', 'passwort_wiederholung' => 'neues-langes-passwort']);
        self::assertSame(422, $falsch->status);
        self::assertStringContainsString('Das aktuelle Passwort stimmt nicht.', $falsch->body);

        $kurz = $this->post('/app/konto/passwort', ['aktuell' => Benutzerdaten::PASSWORT, 'passwort' => 'kurz', 'passwort_wiederholung' => 'kurz']);
        self::assertStringContainsString('mindestens 12 Zeichen', $kurz->body);

        $neu = 'neues-langes-passwort';
        self::assertWeiterleitungNach('/app/konto/passwort', $this->post('/app/konto/passwort', ['aktuell' => Benutzerdaten::PASSWORT, 'passwort' => $neu, 'passwort_wiederholung' => $neu]));
        self::assertStringContainsString('Ihr Passwort ist geändert.', $this->get('/app/konto/passwort')->body, 'this session lives on');
        self::assertTrue(password_verify($neu, new BenutzerRepository($this->pdo())->findById($id)?->passwordHash ?? ''));
        self::assertStringContainsString('Das Passwort Ihres Kontos wurde geändert.', end($this->transport->gesendet)->body);

        $_SESSION = $andereSitzung;
        self::assertSame(302, $this->get('/app/mitglieder')->status, 'the other session is over');
    }

    /** The settings section is the admin's; the Vorstand sees none of it. */
    public function testTheVorstandHasNoUserAdministration(): void
    {
        $id = Benutzerdaten::anlegen($this->pdo());
        $this->anmelden();

        self::assertSame(403, $this->get('/admin')->status);
        self::assertSame(403, $this->post('/admin/benutzer', ['name' => 'X', 'email' => 'x@example.org', 'rolle' => 'admin'])->status);
        self::assertSame(403, $this->post('/admin/benutzer/' . $id . '/rolle', ['rolle' => 'admin'])->status);
        self::assertSame(Rolle::Vorstand, new BenutzerRepository($this->pdo())->findById($id)?->rolle);
    }
}
