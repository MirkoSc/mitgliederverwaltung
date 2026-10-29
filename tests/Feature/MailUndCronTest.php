<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Service\Mail\MailTransport;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeMailTransport;

/** /cron and the settings section Mail through the whole application (01 sections 5 and 6). */
final class MailUndCronTest extends AppTestCase
{
    private FakeMailTransport $transport;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeMailTransport();
        $this->dienste->dienst(MailTransport::class, fn(): MailTransport => $this->transport);
    }

    /** @return array<string, string> */
    private static function formular(): array
    {
        return [
            'host' => 'smtp.example.org',
            'port' => '587',
            'sicherheit' => 'starttls',
            'benutzer' => 'verein',
            'passwort' => 'geheimes-passwort',
            'absender' => 'verein@example.org',
            'antwort_an' => '',
            'vereinsname' => 'Sportverein Beispielstadt',
            'oeffentliche_url' => '',
        ];
    }

    public function testCronWithoutOrWithAWrongTokenIsRefused(): void
    {
        self::assertSame(403, $this->get('/cron')->status);
        self::assertSame(403, $this->get('/cron', ['token' => 'falsch'])->status);
    }

    public function testCronWithTheTokenSendsTheQueuedMails(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/mail', self::formular());
        $this->abmelden();
        $this->dienste->pdo()->exec("INSERT INTO mail_queue (to_address, subject, body, next_try_at, created_at, updated_at)
            VALUES ('a@example.org', 'Betreff', 'Text', '2000-01-01 00:00:00', NOW(), NOW())");

        $antwort = $this->get('/cron', ['token' => 'test-token']);

        self::assertSame(200, $antwort->status);
        /** @var array{status: string, aufgaben: list<array{name: string, erledigt: int}>} $json */
        $json = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ok', $json['status']);
        self::assertContains(['name' => 'mail_versenden', 'erledigt' => 1], $json['aufgaben']);
        self::assertCount(1, $this->transport->gesendet);
    }

    public function testTheSettingsPageShowsTheMailSection(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get('/admin')->body;

        self::assertStringContainsString('<h2 id="titel-mail">Mail</h2>', $seite);
        self::assertStringContainsString('action="/admin/mail/test"', $seite);
    }

    public function testSavingKeepsThePasswordOutOfThePageAndTheDatabase(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/mail', self::formular());

        self::assertWeiterleitungNach('/admin#mail', $antwort);
        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('value="smtp.example.org"', $seite);
        self::assertStringContainsString('Ein Passwort ist gespeichert', $seite);
        self::assertStringNotContainsString('geheimes-passwort', $seite);
        $werte = implode('|', $this->dienste->pdo()->query('SELECT value FROM setting')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertStringNotContainsString('geheimes-passwort', $werte);
    }

    public function testAnEmptyPasswordFieldKeepsTheStoredOne(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/mail', self::formular());

        $this->post('/admin/mail', ['passwort' => ''] + self::formular());
        $this->post('/admin/mail/test', ['empfaenger' => 'admin@example.org']);

        self::assertCount(1, $this->transport->gesendet);
    }

    public function testInvalidInputIsRefusedWithoutSaving(): void
    {
        $this->als(Rolle::Admin);

        $this->post('/admin/mail', ['absender' => 'kein-absender'] + self::formular());

        self::assertStringContainsString('Absenderadresse', $this->get('/admin')->body);
        self::assertSame('0', (string) $this->dienste->pdo()->query("SELECT COUNT(*) FROM setting WHERE name = 'mail_smtp_host'")->fetchColumn());
    }

    public function testTheTestMailButtonSendsAMailToTheFakeTransport(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/mail', self::formular());

        $antwort = $this->post('/admin/mail/test', ['empfaenger' => 'admin@example.org']);

        self::assertWeiterleitungNach('/admin#mail', $antwort);
        self::assertCount(1, $this->transport->gesendet);
        self::assertSame('admin@example.org', $this->transport->gesendet[0]->to);
        self::assertStringContainsString('Die Testmail wurde versendet', $this->get('/admin')->body);
    }

    public function testAFailedTestMailShowsTheReason(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/mail', self::formular());
        $this->transport->fehlschlaege = 1;

        $this->post('/admin/mail/test', ['empfaenger' => 'admin@example.org']);

        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('konnte nicht versendet werden', $seite);
        self::assertStringContainsString('Code 451', $seite);
    }

    public function testTheTestMailNeedsAValidRecipient(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/mail', self::formular());

        $this->post('/admin/mail/test', ['empfaenger' => 'keine-adresse']);

        self::assertSame([], $this->transport->gesendet);
    }

    public function testTheBoardCannotTouchTheMailSettings(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/admin/mail', self::formular())->status);
        self::assertSame(403, $this->post('/admin/mail/test', ['empfaenger' => 'a@example.org'])->status);
    }

    public function testWithoutTheCsrfTokenNothingIsSaved(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/mail', self::formular(), mitToken: false);

        self::assertNotSame(302, $antwort->status);
        self::assertSame('0', (string) $this->dienste->pdo()->query("SELECT COUNT(*) FROM setting WHERE name = 'mail_smtp_host'")->fetchColumn());
    }
}
